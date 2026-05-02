<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\ClientConsent;
use App\Models\Client;
use App\Models\ConsentType;
use App\Services\Operations\OpsNotificationService;
use Illuminate\Http\Request;

class ClientConsentController extends Controller
{
    public function index(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless(
            $auth && ($auth->canDo('clients.viewAny') || $auth->canDo('clients.viewAssigned')),
            403,
        );

        $client = Client::findOrFail($client);

        $consents = ClientConsent::where('client_id', $client->id)
            ->with(['consentType', 'givenBy:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $stats = [
            'total' => $consents->count(),
            'active' => $consents->filter(fn($c) => $c->isValid())->count(),
            'expiring_soon' => $consents->filter(fn($c) => $c->isExpiringSoon())->count(),
            'expired' => $consents->filter(fn($c) => $c->isExpired())->count(),
            'withdrawn' => $consents->where('status', 'withdrawn')->count(),
        ];

        $consentTypes = ConsentType::active()->orderBy('name')->get(['id', 'name', 'category', 'is_mandatory', 'requires_capacity_assessment']);

        return inertia('operations/clients/consents/Index', [
            'client' => $client,
            'consents' => $consents,
            'stats' => $stats,
            'consent_types' => $consentTypes,
        ]);
    }

    public function store(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clients.update'), 403);

        $client = Client::findOrFail($client);

        $data = $request->validate([
            'consent_type_id' => ['required', 'exists:consent_types,id'],
            'status' => ['required', 'in:given,refused'],
            'given_method' => ['required', 'in:written,verbal,electronic'],
            'given_at' => ['required', 'date'],
            'given_by_relationship' => ['nullable', 'string', 'max:255'],
            'given_notes' => ['nullable', 'string', 'max:2000'],
            'conditions' => ['nullable', 'array'],
            'special_conditions' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date'],
            'evidence_type' => ['nullable', 'string', 'max:100'],
            // Capacity assessment
            'capacity_assessed' => ['nullable', 'boolean'],
            'capacity_outcome' => ['nullable', 'in:has_capacity,lacks_capacity,fluctuating'],
            'capacity_notes' => ['nullable', 'string', 'max:2000'],
            // Best interests
            'best_interests_decision' => ['nullable', 'boolean'],
            'best_interests_rationale' => ['nullable', 'string', 'max:2000'],
            'best_interests_consultees' => ['nullable', 'array'],
            // Refusal
            'refusal_reason' => ['nullable', 'string', 'max:2000'],
            // Document upload
            'signed_document' => ['nullable', 'file', 'max:10240'],
        ]);

        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $data['consent_type_id'],
            'status' => $data['status'],
            'given_method' => $data['given_method'],
            'given_at' => $data['given_at'],
            'given_by_user_id' => $auth->id,
            'given_by_relationship' => $data['given_by_relationship'] ?? null,
            'given_notes' => $data['given_notes'] ?? null,
            'conditions' => $data['conditions'] ?? null,
            'special_conditions' => $data['special_conditions'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'evidence_type' => $data['evidence_type'] ?? null,
            'capacity_assessed' => $data['capacity_assessed'] ?? false,
            'capacity_outcome' => $data['capacity_outcome'] ?? null,
            'capacity_notes' => $data['capacity_notes'] ?? null,
            'capacity_assessor_id' => ($data['capacity_assessed'] ?? false) ? $auth->id : null,
            'capacity_assessed_at' => ($data['capacity_assessed'] ?? false) ? now() : null,
            'best_interests_decision' => $data['best_interests_decision'] ?? false,
            'best_interests_decision_maker_id' => ($data['best_interests_decision'] ?? false) ? $auth->id : null,
            'best_interests_decision_at' => ($data['best_interests_decision'] ?? false) ? now() : null,
            'best_interests_rationale' => $data['best_interests_rationale'] ?? null,
            'best_interests_consultees' => $data['best_interests_consultees'] ?? null,
            'refused_at' => $data['status'] === 'refused' ? ($data['given_at'] ?? now()) : null,
            'refusal_reason' => $data['refusal_reason'] ?? null,
            'created_by' => $auth->id,
        ]);

        // Handle document upload
        if ($request->hasFile('signed_document')) {
            $path = $request->file('signed_document')->store("consent-documents/{$client->id}", 'public');
            $consent->update(['signed_document_path' => $path]);
        }

        app(OpsNotificationService::class)->notifyCrud($auth, $data['status'] === 'refused' ? 'refused' : 'recorded', 'consent', $consent, $client);

        return redirect()->back()->with('success', 'Consent recorded successfully.');
    }

    public function withdraw(Request $request, $client, $consent)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clients.update'), 403);

        $consent = ClientConsent::where('client_id', $client)->findOrFail($consent);

        $data = $request->validate([
            'withdrawal_reason' => ['required', 'string', 'max:2000'],
        ]);

        $consent->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
            'withdrawn_by_user_id' => $auth->id,
            'withdrawal_reason' => $data['withdrawal_reason'],
            'updated_by' => $auth->id,
        ]);

        app(OpsNotificationService::class)->notifyCrud($auth, 'withdrawn', 'consent', $consent, Client::find($client));

        return redirect()->back()->with('success', 'Consent withdrawn.');
    }
}
