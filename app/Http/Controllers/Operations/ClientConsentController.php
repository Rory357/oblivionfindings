<?php

namespace App\Http\Controllers\Operations;

use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Services\Operations\OpsNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClientConsentController extends Controller
{
    public function __construct(
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        Gate::authorize('viewAny', ClientConsent::class);

        $consents = ClientConsent::where('client_id', $client->id)
            ->with(['consentType', 'givenBy:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $stats = [
            'total' => $consents->count(),
            'active' => $consents->filter(fn ($c) => $c->isValid())->count(),
            'expiring_soon' => $consents->filter(fn ($c) => $c->isExpiringSoon())->count(),
            'expired' => $consents->filter(fn ($c) => $c->isExpired())->count(),
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

    public function store(Request $request, Client $client)
    {
        $auth = $request->user();
        $this->authorize('view', $client);
        Gate::authorize('create', ClientConsent::class);

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

    public function withdraw(
        Request $request,
        Client $client,
        ClientConsent $consent,
    ) {
        $auth = $request->user();
        $this->authorize('view', $client);
        abort_unless($consent->client_id === $client->id, 404);
        Gate::authorize('withdraw', $consent);

        $data = $request->validate([
            'withdrawal_reason' => ['required', 'string', 'max:2000'],
        ]);

        $didWithdraw = DB::transaction(function () use ($auth, $client, $consent, $data): bool {
            $lockedConsent = ClientConsent::query()
                ->lockForUpdate()
                ->findOrFail($consent->id);

            abort_unless($lockedConsent->client_id === $client->id, 404);
            Gate::forUser($auth)->authorize('withdraw', $lockedConsent);

            if ($lockedConsent->status === 'withdrawn') {
                if ($lockedConsent->withdrawal_reason === $data['withdrawal_reason']) {
                    return false;
                }

                throw ValidationException::withMessages([
                    'status' => 'This consent has already been withdrawn with a different reason.',
                ]);
            }

            if ($lockedConsent->status !== 'given') {
                throw ValidationException::withMessages([
                    'status' => 'Only a currently given consent can be withdrawn.',
                ]);
            }

            $lockedConsent->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
                'withdrawn_by_user_id' => $auth->id,
                'withdrawal_reason' => $data['withdrawal_reason'],
                'updated_by' => $auth->id,
            ]);
            $this->trackingPrivacy->stopForConsent($lockedConsent, $auth->id);

            return true;
        });

        if ($didWithdraw) {
            app(OpsNotificationService::class)->notifyCrud($auth, 'withdrawn', 'consent', $consent->fresh(), $client);
        }

        return redirect()->back()
            ->with('success', 'Consent withdrawn. Tracking collection and live location access stopped.')
            ->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
            ]);
    }
}
