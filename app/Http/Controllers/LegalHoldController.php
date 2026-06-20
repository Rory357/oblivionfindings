<?php

namespace App\Http\Controllers;

use App\Models\LegalHold;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegalHoldController extends Controller
{
    /**
     * Display a listing of legal holds.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canDo('privacy.manageLegalHolds'), 403);

        $query = LegalHold::query()
            ->with(['imposedBy', 'releasedBy']);

        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('hold_reference', 'like', "%{$request->q}%")
                    ->orWhere('reason', 'like', "%{$request->q}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('hold_type')) {
            $query->where('hold_type', $request->hold_type);
        }

        $query->orderByDesc('imposed_at');

        $holds = $query->paginate(20)->withQueryString();

        return Inertia::render('privacy/legal-holds', [
            'holds' => $holds,
            'filters' => $request->only(['q', 'status', 'hold_type']),
            'stats' => [
                'total' => LegalHold::count(),
                'active' => LegalHold::active()->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new legal hold.
     */
    public function create(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.manageLegalHolds'), 403);

        return redirect('/privacy/dashboard?new=hold');
    }

    /**
     * Store a newly created legal hold.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.manageLegalHolds'), 403);

        $validated = $request->validate([
            'hold_type' => 'required|in:litigation,investigation,regulatory,audit,other',
            'reason' => 'required|string',
            'holdable_type' => 'nullable|required_with:holdable_id|string',
            'holdable_id' => 'nullable|required_with:holdable_type|integer',
            'related_records' => 'nullable|array',
            'legal_authority' => 'nullable|string',
            'review_date' => 'nullable|date',
        ]);

        $validated['hold_reference'] = 'LH-' . now()->year . '-' . str_pad(
            LegalHold::whereYear('created_at', now()->year)->count() + 1,
            4,
            '0',
            STR_PAD_LEFT
        );
        $validated['status'] = 'active';
        $validated['imposed_at'] = now();
        $validated['imposed_by_user_id'] = auth()->id();

        $hold = LegalHold::create($validated);

        $message = 'Legal hold created with reference: ' . $hold->hold_reference;

        if ($request->boolean('_modal')) {
            return back()->with('success', $message);
        }

        return redirect()
            ->route('privacy.legal-holds.index')
            ->with('success', $message);
    }

    /**
     * Show the form for editing the legal hold.
     */
    public function edit(Request $request, LegalHold $hold): Response
    {
        abort_unless($request->user()?->canDo('privacy.manageLegalHolds'), 403);

        return Inertia::render('privacy/legal-holds/edit', [
            'hold' => $hold,
        ]);
    }

    /**
     * Update the specified legal hold.
     */
    public function update(Request $request, LegalHold $hold): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.manageLegalHolds'), 403);

        $validated = $request->validate([
            'reason' => 'sometimes|string',
            'related_records' => 'nullable|array',
            'legal_authority' => 'nullable|string',
            'review_date' => 'nullable|date',
        ]);

        $hold->update($validated);

        return back()->with('success', 'Legal hold updated.');
    }

    /**
     * Release the legal hold.
     */
    public function release(Request $request, LegalHold $hold): RedirectResponse
    {
        abort_unless($request->user()?->canDo('privacy.manageLegalHolds'), 403);

        $request->validate([
            'release_reason' => 'required|string',
        ]);

        $hold->update([
            'status' => 'released',
            'released_at' => now(),
            'released_by_user_id' => auth()->id(),
            'release_reason' => $request->release_reason,
        ]);

        return back()->with('success', 'Legal hold released.');
    }
}
