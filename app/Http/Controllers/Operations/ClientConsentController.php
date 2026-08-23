<?php

namespace App\Http\Controllers\Operations;

use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Services\AuditLogger;
use App\Services\Consents\ConsentEvidenceService;
use App\Services\Operations\OpsNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClientConsentController extends Controller
{
    public function __construct(
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
        private readonly ConsentEvidenceService $evidence,
    ) {}

    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        Gate::authorize('viewAny', ClientConsent::class);

        $consents = ClientConsent::where('client_id', $client->id)
            ->where('site_id', $client->site_id)
            ->with(['consentType', 'givenBy:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->each(function (ClientConsent $consent) use ($request, $client): void {
                $consent->setRelation('client', $client);
                $consent->setAttribute('is_consumable', $consent->isValid());

                if ($consent->hasDownloadableSignedDocument()
                    && Gate::forUser($request->user())->allows('downloadEvidence', $consent)) {
                    $consent->setAttribute('signed_document_download_url', route(
                        'operations.clients.consents.evidence.download',
                        [$client, $consent],
                    ));
                    $consent->setAttribute(
                        'signed_document_name',
                        $consent->signed_document_original_name ?: 'Signed consent document',
                    );
                }

                $consent->unsetRelation('client');
            });

        $stats = [
            'total' => $consents->count(),
            'active' => $consents->filter(fn ($c) => $c->isValid())->count(),
            'expiring_soon' => $consents->filter(fn ($c) => $c->isValid() && $c->isExpiringSoon())->count(),
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
        abort_unless($auth && $auth->can('view', $client), 404);
        Gate::forUser($auth)->authorize('create', ClientConsent::class);

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
            'signed_document' => [
                'nullable',
                File::types(['pdf', 'jpg', 'jpeg', 'png'])
                    ->max(intdiv(ConsentEvidenceService::MAX_BYTES, 1024)),
            ],
        ]);

        $conditions = is_array($data['conditions'] ?? null) ? $data['conditions'] : [];
        foreach (['decision_evidence', 'authority_next_of_kin_id', 'consent_request_id'] as $reservedKey) {
            if (array_key_exists($reservedKey, $conditions)) {
                throw ValidationException::withMessages([
                    'conditions' => 'Verified consent authority and decision provenance cannot be supplied directly.',
                ]);
            }
        }

        if (in_array(
            $data['given_by_relationship'] ?? null,
            ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS,
            true,
        )) {
            throw ValidationException::withMessages([
                'given_by_relationship' => 'Substituted consent must use the verified consent request workflow.',
            ]);
        }

        if (
            ($data['capacity_assessed'] ?? false)
            || filled($data['capacity_outcome'] ?? null)
            || filled($data['capacity_notes'] ?? null)
            || ($data['best_interests_decision'] ?? false)
            || filled($data['best_interests_rationale'] ?? null)
            || filled($data['best_interests_consultees'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'capacity_assessed' => 'Decision-specific capacity and substitute-decision evidence must be completed through the consent request workflow.',
            ]);
        }

        $preparedEvidence = $request->hasFile('signed_document')
            ? $this->evidence->prepare($request->file('signed_document'), (int) $auth->id)
            : [];

        try {
            $result = DB::transaction(function () use ($auth, $client, $data, $preparedEvidence, $request): array {
                // Serialize all evidence commands for the canonical Client so
                // simultaneous retries converge before the unique guard.
                $lockedClient = Client::query()
                    ->lockForUpdate()
                    ->findOrFail($client->id);
                abort_unless(
                    (int) $lockedClient->site_id === (int) $client->site_id
                        && $auth->can('view', $lockedClient),
                    404,
                );

                $consentType = ConsentType::query()
                    ->whereKey($data['consent_type_id'])
                    ->where('active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
                $consentTypeVersion = ConsentTypeVersion::query()->firstOrCreate(
                    [
                        'consent_type_id' => $consentType->id,
                        'version' => $consentType->version,
                    ],
                    [
                        'description' => $consentType->description,
                        'purpose' => $consentType->purpose,
                        'legal_basis' => $consentType->legal_basis,
                        'changes_summary' => ['source' => 'canonical_consent_type_version'],
                        'effective_from' => $consentType->created_at ?? now(),
                        'created_by' => $auth->id,
                    ],
                );
                $isAuthoritativeSelfDecision = $data['status'] === 'given'
                    && in_array($data['given_by_relationship'] ?? null, ['self', 'client'], true)
                    && hash_equals(
                        str($consentTypeVersion->purpose)->squish()->lower()->toString(),
                        str($consentType->purpose)->squish()->lower()->toString(),
                    );

                if ($data['status'] === 'given' && ! $isAuthoritativeSelfDecision) {
                    throw ValidationException::withMessages([
                        'status' => 'Authoritative consent must be recorded by the identified Client. Use the representative consent-request workflow for substitute decisions.',
                    ]);
                }

                $decisionAt = Carbon::parse($data['given_at'])->startOfSecond();
                $decisionExpiresAt = isset($data['expires_at'])
                    ? Carbon::parse($data['expires_at'])->startOfSecond()
                    : null;

                $evidenceCommandDigest = null;
                if ($preparedEvidence !== []) {
                    $evidenceCommandDigest = $this->evidence->commandDigest([
                        'contract' => 'consent-evidence-store-v1',
                        'actor_id' => (int) $auth->id,
                        'client_id' => (int) $lockedClient->id,
                        'client_user_id' => $lockedClient->user_id !== null
                            ? (int) $lockedClient->user_id
                            : null,
                        'site_id' => (int) $lockedClient->site_id,
                        'consent_type_id' => (int) $consentType->id,
                        'consent_type_version_id' => (int) $consentTypeVersion->id,
                        'status' => $data['status'],
                        'given_method' => $data['given_method'],
                        'given_at' => $decisionAt->toISOString(),
                        'given_by_relationship' => $data['given_by_relationship'] ?? null,
                        'given_notes' => $data['given_notes'] ?? null,
                        'conditions' => $data['conditions'] ?? null,
                        'special_conditions' => $data['special_conditions'] ?? null,
                        'expires_at' => $decisionExpiresAt?->toISOString(),
                        'evidence_type' => $data['evidence_type'] ?? null,
                        'refusal_reason' => $data['refusal_reason'] ?? null,
                        'file' => [
                            'original_name' => $preparedEvidence['signed_document_original_name'],
                            'mime_type' => $preparedEvidence['signed_document_mime_type'],
                            'size_bytes' => $preparedEvidence['signed_document_size_bytes'],
                            'sha256' => $preparedEvidence['signed_document_sha256'],
                        ],
                    ]);

                    $existing = ClientConsent::query()
                        ->withTrashed()
                        ->where('signed_document_command_sha256', $evidenceCommandDigest)
                        ->first();
                    if ($existing !== null) {
                        $this->evidence->discard($preparedEvidence);

                        if ($existing->trashed()) {
                            throw ValidationException::withMessages([
                                'signed_document' => 'This consent evidence was already recorded and is no longer available.',
                            ]);
                        }

                        return ['consent' => $existing, 'created' => false];
                    }
                }

                $consent = ClientConsent::create([
                    'client_id' => $lockedClient->id,
                    'site_id' => $lockedClient->site_id,
                    'consent_type_id' => $data['consent_type_id'],
                    'consent_type_version_id' => $consentTypeVersion->id,
                    'decision_state' => $isAuthoritativeSelfDecision
                        ? ClientConsent::DECISION_AUTHORITATIVE
                        : ClientConsent::DECISION_GOVERNANCE_REVIEW,
                    'decision_basis' => $isAuthoritativeSelfDecision ? ClientConsent::BASIS_SELF : null,
                    'decision_client_id' => $isAuthoritativeSelfDecision ? $lockedClient->id : null,
                    'decision_actor_user_id' => $isAuthoritativeSelfDecision ? $lockedClient->user_id : null,
                    'decision_purpose' => $isAuthoritativeSelfDecision ? $consentTypeVersion->purpose : null,
                    'decision_contract_version' => $isAuthoritativeSelfDecision ? 1 : null,
                    'decision_evidence' => $isAuthoritativeSelfDecision ? [
                        'source' => 'operations_manual',
                        'identity_source' => 'canonical_client_record',
                        'client_id' => $lockedClient->id,
                        'site_id' => $lockedClient->site_id,
                        'consent_type_id' => $consentType->id,
                        'consent_type_version_id' => $consentTypeVersion->id,
                        'consent_type_purpose' => $consentTypeVersion->purpose,
                        'decision_client_id' => $lockedClient->id,
                        'decision_actor_user_id' => $lockedClient->user_id,
                        'decision_actor_kind' => 'identified_client_self',
                        'authority_basis' => ClientConsent::BASIS_SELF,
                        'recorder_user_id' => $auth->id,
                        'assertion_method' => $data['given_method'],
                        'evidence_type' => $data['evidence_type'] ?? null,
                        'decision_at' => $decisionAt->toISOString(),
                        'decision_expires_at' => $decisionExpiresAt?->toISOString(),
                        'recorded_at' => now()->toISOString(),
                    ] : null,
                    'gate_satisfying' => $isAuthoritativeSelfDecision,
                    'governance_review_reason' => $isAuthoritativeSelfDecision
                        ? null
                        : 'non_authoritative_manual_record',
                    'status' => $data['status'],
                    'given_method' => $data['given_method'],
                    'given_at' => $decisionAt,
                    'given_by_user_id' => $auth->id,
                    'given_by_relationship' => $data['given_by_relationship'] ?? null,
                    'given_notes' => $data['given_notes'] ?? null,
                    'conditions' => $data['conditions'] ?? null,
                    'special_conditions' => $data['special_conditions'] ?? null,
                    'expires_at' => $decisionExpiresAt,
                    'evidence_type' => $data['evidence_type'] ?? null,
                    'capacity_assessed' => false,
                    'capacity_outcome' => null,
                    'capacity_notes' => null,
                    'capacity_assessor_id' => null,
                    'capacity_assessed_at' => null,
                    'best_interests_decision' => false,
                    'best_interests_decision_maker_id' => null,
                    'best_interests_decision_at' => null,
                    'best_interests_rationale' => null,
                    'best_interests_consultees' => null,
                    'refused_at' => $data['status'] === 'refused' ? ($data['given_at'] ?? now()) : null,
                    'refusal_reason' => $data['refusal_reason'] ?? null,
                    'created_by' => $auth->id,
                    'signed_document_command_sha256' => $evidenceCommandDigest,
                    ...$preparedEvidence,
                ]);

                if ($preparedEvidence !== []) {
                    AuditLogger::logOrFail('consents.evidence.attached', $consent, [
                        'site_id' => $lockedClient->site_id,
                        'malware_disposition' => $preparedEvidence['signed_document_malware_disposition'],
                        'mime_type' => $preparedEvidence['signed_document_mime_type'],
                        'size_bytes' => $preparedEvidence['signed_document_size_bytes'],
                    ], $request);
                }

                return ['consent' => $consent, 'created' => true];
            }, 3);
        } catch (Throwable $exception) {
            if ($preparedEvidence !== []) {
                $this->evidence->discard($preparedEvidence);
            }

            throw $exception;
        }

        /** @var ClientConsent $consent */
        $consent = $result['consent'];

        if ($result['created']) {
            app(OpsNotificationService::class)->notifyCrud($auth, $data['status'] === 'refused' ? 'refused' : 'recorded', 'consent', $consent, $client);
        }

        return redirect()->back()->with('success', 'Consent recorded successfully.');
    }

    public function downloadEvidence(
        Request $request,
        Client $client,
        ClientConsent $consent,
    ): StreamedResponse {
        $actor = $request->user();

        // Conceal foreign Site, parent and nested-record identifiers before
        // evaluating the action permission or touching the evidence object.
        abort_unless($actor && $actor->can('view', $client), 404);
        abort_unless(
            (int) $consent->client_id === (int) $client->id
                && (int) $consent->site_id === (int) $client->site_id,
            404,
        );

        $stream = null;
        try {
            $download = DB::transaction(function () use ($actor, $client, $consent, $request, &$stream): array {
                // A retried transaction must never retain a stream opened by a
                // failed attempt.
                if (is_resource($stream)) {
                    fclose($stream);
                    $stream = null;
                }

                $lockedClient = Client::query()
                    ->lockForUpdate()
                    ->findOrFail($client->id);
                abort_unless(
                    (int) $lockedClient->site_id === (int) $client->site_id
                        && $actor->can('view', $lockedClient),
                    404,
                );

                $lockedConsent = ClientConsent::query()
                    ->lockForUpdate()
                    ->findOrFail($consent->id);
                abort_unless(
                    (int) $lockedConsent->client_id === (int) $lockedClient->id
                        && (int) $lockedConsent->site_id === (int) $lockedClient->site_id,
                    404,
                );
                $lockedConsent->setRelation('client', $lockedClient);
                Gate::forUser($actor)->authorize('downloadEvidence', $lockedConsent);

                $stream = $this->evidence->openVerifiedStream($lockedConsent);
                abort_unless(is_resource($stream), 404);

                AuditLogger::logOrFail('consents.evidence.downloaded', $lockedConsent, [
                    'site_id' => $lockedClient->site_id,
                    'status' => $lockedConsent->status,
                    'malware_disposition' => $lockedConsent->signed_document_malware_disposition,
                ], $request);

                return [
                    'name' => $lockedConsent->signed_document_original_name ?: 'signed-consent-document',
                    'mime_type' => $lockedConsent->signed_document_mime_type,
                    'size_bytes' => (int) $lockedConsent->signed_document_size_bytes,
                ];
            }, 3);
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $exception;
        }

        abort_unless(is_resource($stream), 404);

        return response()->streamDownload(
            static function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            $download['name'],
            [
                'Content-Type' => $download['mime_type'],
                'Content-Length' => (string) $download['size_bytes'],
                'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
            ],
        );
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
