<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteReferral;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteReferralController extends Controller
{
    public function index(Request $request): Response
    {
        $query = RespiteReferral::query()->with('client');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('urgency')) {
            $query->where('urgency', $request->urgency);
        }
        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('referrer_name', 'like', "%{$request->q}%")
                    ->orWhere('referral_reason', 'like', "%{$request->q}%");
            });
        }

        $query->orderByDesc('received_at');
        $referrals = $query->paginate(20)->withQueryString();

        return Inertia::render('respite/index', [
            'referrals' => $referrals,
            'filters' => $request->only(['status', 'urgency', 'q']),
            'stats' => [
                'received' => RespiteReferral::where('status', 'received')->count(),
                'triaged' => RespiteReferral::where('status', 'triaged')->count(),
                'accepted' => RespiteReferral::where('status', 'accepted')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('respite/referrals/create', [
            'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->orderBy('first_name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'referrer_type' => 'nullable|string',
            'referrer_name' => 'required|string|max:255',
            'referrer_contact' => 'nullable|string|max:255',
            'referral_reason' => 'required|string',
            'urgency' => 'required|in:planned,urgent,crisis',
            'received_at' => 'nullable|date',
            'triage_notes' => 'nullable|string',
            'risk_level' => 'nullable|in:low,medium,high,critical',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->authorize('view', $client);

        $validated['received_at'] = $validated['received_at'] ?? now();
        $validated['created_by'] = auth()->id();

        $referral = RespiteReferral::create($validated);

        event(new RespiteEvent('respite.referral.created', [
            'id' => $referral->id,
            'client_id' => $referral->client_id,
            'status' => $referral->status,
        ]));

        return redirect()
            ->route('respite.referrals.show', $referral)
            ->with('success', 'Respite referral created.');
    }

    public function show(RespiteReferral $referral): Response
    {
        $referral->load('client');
        $this->authorize('view', $referral->client);

        return Inertia::render('respite/referrals/show', [
            'referral' => $referral,
        ]);
    }

    public function update(Request $request, RespiteReferral $referral): RedirectResponse
    {
        $referral->loadMissing('client');
        $this->authorize('view', $referral->client);

        $validated = $request->validate([
            'status' => 'sometimes|in:received,triaged,accepted,declined',
            'triage_notes' => 'nullable|string',
            'risk_level' => 'nullable|in:low,medium,high,critical',
        ]);

        $validated['updated_by'] = auth()->id();
        $referral->update($validated);

        event(new RespiteEvent('respite.referral.updated', [
            'id' => $referral->id,
            'client_id' => $referral->client_id,
            'status' => $referral->status,
        ]));

        return back()->with('success', 'Referral updated.');
    }
}
