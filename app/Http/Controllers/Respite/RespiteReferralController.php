<?php

namespace App\Http\Controllers\Respite;

use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteReferral;
use App\Support\Respite\RespiteFundingSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'new_client.nhi_number' => 'nullable|string|max:20|regex:/^[A-Z]{3}\d{4}$/i',
            'new_client.site_id' => 'nullable|exists:sites,id',
            'nhi_number' => 'nullable|string|max:20|regex:/^[A-Z]{3}\d{4}$/i',
            'referrer_type' => 'nullable|string|max:255',
            'referrer_name' => 'required|string|max:255',
            'referrer_contact' => 'nullable|string|max:255',
            'third_party_source_type' => 'nullable|string|max:255',
            'third_party_source_name' => 'nullable|string|max:255',
            'third_party_collection_consent' => 'nullable|boolean',
            'referral_reason' => 'required|string',
            'urgency' => 'required|in:planned,urgent,crisis',
            'funding_source' => ['nullable', Rule::in(RespiteFundingSource::keys())],
            'funding_reference' => 'nullable|string|max:255',
            'received_at' => 'nullable|date',
            'triage_notes' => 'nullable|string',
            'risk_level' => 'nullable|in:low,medium,high,critical',
            'is_maori' => 'nullable|boolean',
            'ethnicity' => 'nullable|string|max:255',
            'iwi' => 'nullable|string|max:255',
            'hapu' => 'nullable|string|max:255',
            'marae' => 'nullable|string|max:255',
            'interpreter_required' => 'nullable|boolean',
            'interpreter_language' => 'nullable|string|max:255',
            'interpreter_arranged' => 'nullable|boolean',
            'cultural_considerations' => 'nullable|string',
            'cultural_dietary_needs' => 'nullable|string',
            'primary_carer_name' => 'nullable|string|max:255',
            'primary_carer_relationship' => 'nullable|string|max:255',
            'primary_carer_contact' => 'nullable|string|max:255',
            'carer_strain_level' => 'nullable|in:low,moderate,high,at_breakdown',
            'carer_breakdown_flag' => 'nullable|boolean',
            'booker_type' => 'nullable|in:self,whanau,carer,nasc,gp,hospital,whaikaha,egl_connector,other',
        ]);

        $fundingSource = $validated['funding_source'] ?? null;
        $fundingReference = $validated['funding_reference'] ?? null;
        $nhi = Client::normaliseNhi($validated['nhi_number'] ?? ($validated['new_client']['nhi_number'] ?? null));
        $nhiHash = Client::nhiHash($nhi);

        if (! empty($validated['client_id'])) {
            $client = Client::findOrFail($validated['client_id']);
            $this->authorize('view', $client);
        } else {
            $nc = $validated['new_client'] ?? [];
            $client = $this->findClientByNhiHash($nhiHash);

            if (! $client) {
                $client = Client::create([
                    'first_name' => $nc['first_name'],
                    'last_name' => $nc['last_name'] ?? '',
                    'date_of_birth' => $nc['date_of_birth'] ?? null,
                    'nhi_number' => $nhi,
                    'site_id' => $nc['site_id'] ?? null,
                    'funding_type' => RespiteFundingSource::label($fundingSource),
                    'funding_notes' => $fundingReference,
                    'status' => 'active',
                ]);
            }
        }

        $this->syncClientIntakeSnapshot($client, $validated, $nhi);

        $referral = RespiteReferral::create([
            'client_id' => $client->id,
            'nhi_number' => $nhi,
            'nhi_hash' => $nhiHash,
            'referrer_type' => $validated['referrer_type'] ?? null,
            'referrer_name' => $validated['referrer_name'],
            'referrer_contact' => $validated['referrer_contact'] ?? null,
            'third_party_source_type' => $validated['third_party_source_type'] ?? $validated['referrer_type'] ?? null,
            'third_party_source_name' => $validated['third_party_source_name'] ?? $validated['referrer_name'],
            'third_party_collection_consent' => (bool) ($validated['third_party_collection_consent'] ?? false),
            'referral_reason' => $validated['referral_reason'],
            'urgency' => $validated['urgency'],
            'funding_source' => $fundingSource,
            'funding_reference' => $fundingReference,
            'received_at' => $validated['received_at'] ?? now(),
            'triage_notes' => $validated['triage_notes'] ?? null,
            'risk_level' => $validated['risk_level'] ?? null,
            'is_maori' => (bool) ($validated['is_maori'] ?? false),
            'ethnicity' => $validated['ethnicity'] ?? null,
            'iwi' => $validated['iwi'] ?? null,
            'hapu' => $validated['hapu'] ?? null,
            'marae' => $validated['marae'] ?? null,
            'interpreter_required' => (bool) ($validated['interpreter_required'] ?? false),
            'interpreter_language' => $validated['interpreter_language'] ?? null,
            'interpreter_arranged' => (bool) ($validated['interpreter_arranged'] ?? false),
            'cultural_considerations' => $validated['cultural_considerations'] ?? null,
            'cultural_dietary_needs' => $validated['cultural_dietary_needs'] ?? null,
            'primary_carer_name' => $validated['primary_carer_name'] ?? null,
            'primary_carer_relationship' => $validated['primary_carer_relationship'] ?? null,
            'primary_carer_contact' => $validated['primary_carer_contact'] ?? null,
            'carer_strain_level' => $validated['carer_strain_level'] ?? null,
            'carer_breakdown_flag' => (bool) ($validated['carer_breakdown_flag'] ?? false),
            'booker_type' => $validated['booker_type'] ?? null,
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

    private function findClientByNhiHash(?string $nhiHash): ?Client
    {
        if (! $nhiHash) {
            return null;
        }

        $client = Client::where('nhi_hash', $nhiHash)->first();

        if ($client) {
            return $client;
        }

        return Client::whereNull('nhi_hash')
            ->get(['id', 'nhi_number'])
            ->first(function (Client $candidate) use ($nhiHash) {
                if (Client::nhiHash($candidate->nhi_number) !== $nhiHash) {
                    return false;
                }

                $candidate->forceFill(['nhi_hash' => $nhiHash])->save();

                return true;
            })?->fresh();
    }

    private function syncClientIntakeSnapshot(Client $client, array $validated, ?string $nhi): void
    {
        $updates = [
            'nhi_number' => $nhi ?: $client->nhi_number,
            'ethnicity' => $validated['ethnicity'] ?? $client->ethnicity,
            'iwi' => $validated['iwi'] ?? $client->iwi,
            'hapu' => $validated['hapu'] ?? $client->hapu,
            'marae' => $validated['marae'] ?? $client->marae,
            'cultural_dietary_needs' => $validated['cultural_dietary_needs'] ?? $client->cultural_dietary_needs,
            'funding_type' => isset($validated['funding_source'])
                ? RespiteFundingSource::label($validated['funding_source'])
                : $client->funding_type,
            'funding_notes' => $validated['funding_reference'] ?? $client->funding_notes,
        ];

        $client->forceFill(array_filter($updates, fn ($value) => $value !== null && $value !== ''))->save();
    }
}
