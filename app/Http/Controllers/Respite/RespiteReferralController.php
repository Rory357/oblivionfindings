<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteReferral;
use App\Events\Respite\RespiteEvent;
use App\Support\Respite\RespiteFundingSource;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
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
            // Either link an existing client...
            'client_id' => 'nullable|exists:clients,id',
            // ...or capture a new person (a lightweight shell the onboarding
            // wizard completes later). first_name is the only hard requirement.
            'new_client' => 'nullable|array',
            'new_client.first_name' => 'required_without:client_id|string|max:255',
            'new_client.last_name' => 'nullable|string|max:255',
            'new_client.date_of_birth' => 'nullable|date',
            'new_client.nhi_number' => 'nullable|string|max:20',
            'new_client.site_id' => 'nullable|exists:sites,id',
            'referrer_type' => 'nullable|string|max:255',
            'referrer_name' => 'required|string|max:255',
            'referrer_contact' => 'nullable|string|max:255',
            'referral_reason' => 'required|string',
            'urgency' => 'required|in:planned,urgent,crisis',
            'funding_source' => ['nullable', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'received_at' => 'nullable|date',
            'triage_notes' => 'nullable|string',
            'risk_level' => 'nullable|in:low,medium,high,critical',
        ]);

        $fundingSource = $validated['funding_source'] ?? null;
        $fundingReference = $validated['funding_reference'] ?? null;

        if (! empty($validated['client_id'])) {
            $client = Client::findOrFail($validated['client_id']);
            $this->authorize('view', $client);
        } else {
            $nc = $validated['new_client'] ?? [];
            // NB: NHI duplicate-detection isn't done here — nhi_number is an
            // encrypted column, so it can't be matched by a plaintext query.
            // Proper dedup needs a deterministic nhi_hash column on clients
            // (tracked in the NZ gap analysis).
            $client = Client::create([
                'first_name' => $nc['first_name'],
                'last_name' => $nc['last_name'] ?? '',
                'date_of_birth' => $nc['date_of_birth'] ?? null,
                'nhi_number' => $nc['nhi_number'] ?? null,
                'site_id' => $nc['site_id'] ?? null,
                'funding_type' => RespiteFundingSource::label($fundingSource),
                'funding_notes' => $fundingReference,
                'status' => 'active',
            ]);
        }

        $referral = RespiteReferral::create([
            'client_id' => $client->id,
            'referrer_type' => $validated['referrer_type'] ?? null,
            'referrer_name' => $validated['referrer_name'],
            'referrer_contact' => $validated['referrer_contact'] ?? null,
            'referral_reason' => $validated['referral_reason'],
            'urgency' => $validated['urgency'],
            'funding_source' => $fundingSource,
            'funding_reference' => $fundingReference,
            'received_at' => $validated['received_at'] ?? now(),
            'triage_notes' => $validated['triage_notes'] ?? null,
            'risk_level' => $validated['risk_level'] ?? null,
            'created_by' => auth()->id(),
        ]);

        event(new RespiteEvent('respite.referral.created', [
            'id' => $referral->id,
            'client_id' => $referral->client_id,
            'status' => $referral->status,
        ]));

        return back()->with('success', 'Respite referral created.');
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
