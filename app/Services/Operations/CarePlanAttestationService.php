<?php

namespace App\Services\Operations;

use App\Models\CarePlan;
use App\Models\CarePlanSignOff;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\NextOfKin;
use App\Models\StaffCredential;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CarePlanAttestationService
{
    public const DIGEST_ALGORITHM = 'sha256';

    public const DIGEST_PAYLOAD_VERSION = 1;

    private const SOURCE_OPERATIONS = 'operations';

    private const SOURCE_PORTAL = 'portal';

    private const VALID_REQUIREMENTS = ['eligible_attestation', 'not_required'];

    private const EVIDENCE_TYPES = [
        'witness_statement',
        'signed_document',
        'recording_reference',
        'governance_record',
    ];

    private const CLINICAL_CREDENTIAL_TYPES = [
        'registered_nurse',
        'nurse_practitioner',
        'medical_practitioner',
        'allied_health_practitioner',
        'clinical_practitioner',
    ];

    public function __construct(
        private readonly TimelineEmitter $timeline,
    ) {}

    /**
     * The controller deliberately does not use database-backed `exists` rules
     * for signer/evidence identifiers. Eligibility is resolved only after the
     * plan and actor context are authorised, under the service transaction.
     *
     * @return array<string, array<int, mixed>>
     */
    public function validationRules(): array
    {
        return [
            'attestation_state' => ['nullable', Rule::in(CarePlanSignOff::ATTESTATION_STATES)],
            'party_role' => ['required', Rule::in(CarePlanSignOff::PARTY_ROLES)],
            'party_name' => ['nullable', 'string', 'max:160'],
            'relationship' => ['nullable', 'string', 'max:120'],
            'agreed_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['nullable', Rule::in(CarePlanSignOff::METHODS)],
            'acknowledgement' => ['nullable', 'string', 'max:2000'],
            'outcome_reason' => ['nullable', 'string', 'max:2000'],
            'signer_user_id' => ['nullable', 'integer', 'min:1'],
            'signer_client_id' => ['nullable', 'integer', 'min:1'],
            'authority_next_of_kin_id' => ['nullable', 'integer', 'min:1'],
            'capacity_evidence_consent_id' => ['nullable', 'integer', 'min:1'],
            'clinical_credential_id' => ['nullable', 'integer', 'min:1'],
            'authority_basis' => ['nullable', 'string', 'max:100'],
            'capacity_basis' => ['nullable', 'string', 'max:64'],
            'evidence_type' => ['nullable', Rule::in(self::EVIDENCE_TYPES)],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'witness_declaration' => ['sometimes', 'accepted'],
        ];
    }

    /**
     * Persist one immutable, version-bound attestation or evidence-state row.
     */
    public function record(
        CarePlan $carePlan,
        User $actor,
        array $data,
        string $source = self::SOURCE_OPERATIONS,
    ): CarePlanSignOff {
        return DB::transaction(function () use ($carePlan, $actor, $data, $source): CarePlanSignOff {
            $locked = CarePlan::query()
                ->with('client')
                ->lockForUpdate()
                ->findOrFail($carePlan->getKey());

            $this->assertActorContext($locked, $actor, $source);

            if (! $locked->isMutableVersion()) {
                throw ValidationException::withMessages([
                    'care_plan' => 'Only the current working care plan version can be changed.',
                ]);
            }

            $policy = $this->policyFor($locked);
            $digest = $this->currentDigest($locked);
            $this->supersedeStaleAttestations($locked, $digest);

            $state = (string) ($data['attestation_state'] ?? CarePlanSignOff::STATE_RECORDED_PROXY);
            $contract = $this->normaliseContract($locked, $actor, $data, $state, $source, $policy);
            $contract['plan_version'] = (int) ($locked->version ?? 1);
            $contract['plan_version_digest'] = $digest;
            $contract['digest_algorithm'] = self::DIGEST_ALGORITHM;
            $contract['digest_payload_version'] = self::DIGEST_PAYLOAD_VERSION;
            $contract['policy_snapshot'] = $policy;
            $contract['recorded_by'] = $actor->id;

            $submissionPayload = Arr::except($contract, [
                'signer_asserted_at',
                'witnessed_at',
                'identity_provenance',
            ]);
            $submissionFingerprint = $this->hashPayload($submissionPayload);
            $activeIdentityKey = hash(self::DIGEST_ALGORITHM, implode('|', [
                (string) $locked->id,
                $digest,
                (string) $contract['signer_fingerprint'],
            ]));

            $existing = CarePlanSignOff::query()
                ->where('care_plan_id', $locked->id)
                ->where('active_identity_key', $activeIdentityKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (hash_equals((string) $existing->submission_fingerprint, $submissionFingerprint)) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'attestation' => 'An active attestation already exists for this signer and care-plan version. Revoke it before recording a correction.',
                ]);
            }

            $contract['submission_fingerprint'] = $submissionFingerprint;
            $contract['active_identity_key'] = $activeIdentityKey;

            $signOff = $locked->signOffs()->create($contract);

            $this->timeline->record([
                'source_type' => CarePlanSignOff::class,
                'source_id' => $signOff->id,
                'occurred_at' => now(),
                'type' => $signOff->gate_satisfying
                    ? 'care_plan_signed_off'
                    : 'care_plan_attestation_evidence_recorded',
                'actor_user_id' => $actor->id,
                'client_id' => $locked->client_id,
                'site_id' => $locked->client?->site_id,
                'subject' => $signOff->gate_satisfying
                    ? 'Care plan attestation recorded'
                    : 'Care plan attestation evidence recorded',
                'body' => null,
                'meta' => [
                    'care_plan_id' => $locked->id,
                    'care_plan_version' => (int) ($locked->version ?? 1),
                    'attestation_state' => $signOff->attestation_state,
                    'signer_type' => $signOff->signer_type,
                    'party_role' => $signOff->party_role,
                    'recorder_user_id' => $actor->id,
                    'signer_user_id' => $signOff->signer_user_id,
                    'signer_client_id' => $signOff->signer_client_id,
                    'witness_user_id' => $signOff->witnessed_by,
                    'authority_next_of_kin_id' => $signOff->authority_next_of_kin_id,
                    'capacity_evidence_consent_id' => $signOff->capacity_evidence_consent_id,
                    'clinical_credential_id' => $signOff->clinical_credential_id,
                    'authority_basis' => $signOff->authority_basis,
                    'evidence_type' => $signOff->evidence_type,
                    'version_digest' => $digest,
                    'attestation_policy_version' => $policy['version'],
                    'gate_satisfying' => (bool) $signOff->gate_satisfying,
                ],
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $actor->id,
            ]);

            return $signOff;
        }, 3);
    }

    /**
     * The review transition and its attestation gate share the same plan lock.
     * A gate failure is returned from (and therefore commits) the transaction so
     * stale rows can be durably marked as superseded before validation returns.
     */
    public function completeReview(
        CarePlan $carePlan,
        User $actor,
        ?string $reviewNotes = null,
    ): bool {
        $result = DB::transaction(function () use ($carePlan, $actor, $reviewNotes): array {
            $locked = CarePlan::query()
                ->with('client')
                ->lockForUpdate()
                ->findOrFail($carePlan->getKey());

            $this->assertActorContext($locked, $actor, self::SOURCE_OPERATIONS);
            $policy = $this->policyFor($locked);
            $digest = $this->currentDigest($locked);

            if (
                $locked->status === 'active'
                && hash_equals(
                    $digest,
                    (string) data_get($locked->content, 'review_context.completed_version_digest', ''),
                )
            ) {
                return ['completed' => false, 'idempotent' => true];
            }

            if ($locked->status !== 'review') {
                return [
                    'completed' => false,
                    'errors' => ['status' => 'Only an in-progress review can be completed.'],
                ];
            }

            if ($locked->goals()->count() === 0 && ! $this->hasStructuredDomains($locked->content ?? [])) {
                return [
                    'completed' => false,
                    'errors' => [
                        'goals' => 'Cannot activate a care plan without at least one goal or support domain. Please add goals or domains before completing the review.',
                    ],
                ];
            }

            $this->supersedeStaleAttestations($locked, $digest);

            $attestations = CarePlanSignOff::query()
                ->where('care_plan_id', $locked->id)
                ->where('plan_version_digest', $digest)
                ->whereNull('revoked_at')
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->get();

            if (! $this->policyIsSatisfied($policy, $attestations)) {
                return [
                    'completed' => false,
                    'errors' => [
                        'sign_offs' => 'The current care-plan version does not yet have eligible attestation evidence required by its policy.',
                    ],
                ];
            }

            $rootId = $locked->parent_id ?? $locked->id;

            CarePlan::query()
                ->where('client_id', $locked->client_id)
                ->where('id', '!=', $locked->id)
                ->where(function ($query) use ($rootId): void {
                    $query->whereKey($rootId)->orWhere('parent_id', $rootId);
                })
                ->where('status', 'active')
                ->update(['status' => 'archived']);

            $content = $locked->content ?? [];
            if (filled($reviewNotes)) {
                data_set($content, 'review_context.review_notes', $reviewNotes);
            }
            data_set($content, 'review_context.completed_at', now()->toISOString());
            data_set($content, 'review_context.completed_by', $actor->id);
            data_set($content, 'review_context.completed_version_digest', $digest);
            data_set($content, 'review_context.attestation_policy_version', $policy['version']);

            $locked->update([
                'status' => 'active',
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'next_review_at' => $locked->next_review_at ?? now()->addMonths(3),
                'content' => $content,
            ]);

            $this->timeline->record([
                'source_type' => CarePlan::class,
                'source_id' => $locked->id,
                'occurred_at' => now(),
                'type' => 'care_plan_review_completed',
                'actor_user_id' => $actor->id,
                'client_id' => $locked->client_id,
                'site_id' => $locked->client?->site_id,
                'subject' => 'Care plan review completed',
                'body' => null,
                'meta' => [
                    'care_plan_id' => $locked->id,
                    'care_plan_version' => (int) ($locked->version ?? 1),
                    'version_digest' => $digest,
                    'attestation_policy_version' => $policy['version'],
                    'completed_by' => $actor->id,
                ],
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $actor->id,
            ]);

            return ['completed' => true];
        }, 3);

        if (isset($result['errors'])) {
            throw ValidationException::withMessages($result['errors']);
        }

        return (bool) ($result['completed'] ?? false);
    }

    /**
     * Revoke without deleting or rewriting the original attestation evidence.
     */
    public function revoke(
        CarePlan $carePlan,
        CarePlanSignOff|int $signOff,
        User $actor,
        ?string $reason = null,
    ): CarePlanSignOff {
        return DB::transaction(function () use ($carePlan, $signOff, $actor, $reason): CarePlanSignOff {
            $lockedPlan = CarePlan::query()
                ->with('client')
                ->lockForUpdate()
                ->findOrFail($carePlan->getKey());

            $this->assertActorContext($lockedPlan, $actor, self::SOURCE_OPERATIONS);

            if (! $lockedPlan->isMutableVersion()) {
                throw ValidationException::withMessages([
                    'care_plan' => 'Only the current working care plan version can be changed.',
                ]);
            }

            $lockedSignOff = CarePlanSignOff::query()
                ->where('care_plan_id', $lockedPlan->id)
                ->lockForUpdate()
                ->findOrFail($signOff instanceof CarePlanSignOff ? $signOff->id : $signOff);

            if ($lockedSignOff->revoked_at !== null) {
                return $lockedSignOff;
            }

            CarePlanSignOff::query()
                ->whereKey($lockedSignOff->id)
                ->update([
                    'active_identity_key' => null,
                    'revoked_at' => now(),
                    'revoked_by' => $actor->id,
                    'revocation_reason' => filled($reason)
                        ? $reason
                        : 'Revoked by an authorised recorder; original evidence retained.',
                    'updated_at' => now(),
                ]);

            $lockedSignOff->refresh();

            $this->timeline->record([
                'source_type' => CarePlanSignOff::class,
                'source_id' => $lockedSignOff->id,
                'occurred_at' => now(),
                'type' => 'care_plan_attestation_revoked',
                'actor_user_id' => $actor->id,
                'client_id' => $lockedPlan->client_id,
                'site_id' => $lockedPlan->client?->site_id,
                'subject' => 'Care plan attestation revoked',
                'body' => null,
                'meta' => [
                    'care_plan_id' => $lockedPlan->id,
                    'attestation_id' => $lockedSignOff->id,
                    'attestation_state' => $lockedSignOff->attestation_state,
                    'revoked_by' => $actor->id,
                ],
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $actor->id,
            ]);

            return $lockedSignOff;
        }, 3);
    }

    /**
     * Mark active evidence stale after a substantive care-plan definition edit.
     * All writers call this owner rather than interpreting digest validity.
     */
    public function supersedeChangedVersion(CarePlan $carePlan): void
    {
        DB::transaction(function () use ($carePlan): void {
            $locked = CarePlan::query()
                ->lockForUpdate()
                ->findOrFail($carePlan->getKey());

            $this->supersedeStaleAttestations($locked, $this->currentDigest($locked));
        }, 3);
    }

    public function currentDigest(CarePlan $carePlan): string
    {
        $carePlan->unsetRelation('goals');
        $carePlan->load([
            'goals' => fn ($query) => $query->orderBy('id'),
            'goals.steps' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $content = $carePlan->content ?? [];
        unset($content['review_context']);

        $payload = [
            'schema' => 'care-plan-version-digest',
            'schema_version' => self::DIGEST_PAYLOAD_VERSION,
            'care_plan_id' => (int) $carePlan->id,
            'client_id' => (int) $carePlan->client_id,
            'root_plan_id' => (int) ($carePlan->parent_id ?? $carePlan->id),
            'version' => (int) ($carePlan->version ?? 1),
            'title' => (string) $carePlan->title,
            'plan_type' => (string) $carePlan->plan_type,
            'starts_at' => $carePlan->starts_at?->toDateString(),
            'ends_at' => $carePlan->ends_at?->toDateString(),
            'content' => $content,
            'attestation_policy' => $this->policyFor($carePlan),
            'goals' => $carePlan->goals->map(fn ($goal): array => [
                'id' => (int) $goal->id,
                'title' => (string) $goal->title,
                'description' => $goal->description,
                'category' => (string) $goal->category,
                'priority' => (string) $goal->priority,
                'target_date' => $goal->target_date?->toDateString(),
                'steps' => $goal->steps->map(fn ($step): array => [
                    'id' => (int) $step->id,
                    'title' => (string) $step->title,
                    'sort_order' => (int) $step->sort_order,
                    'target_date' => $step->target_date?->toDateString(),
                ])->values()->all(),
            ])->values()->all(),
        ];

        return $this->hashPayload($payload);
    }

    /** @return array<string, mixed> */
    public function policyFor(CarePlan $carePlan): array
    {
        $policy = $carePlan->attestation_policy;
        if (! is_array($policy)) {
            $this->denyEligibility();
        }

        $version = $policy['version'] ?? null;
        $requirement = $policy['requirement'] ?? null;
        $satisfyingStates = $policy['satisfying_states'] ?? null;

        if (
            $version !== 1
            || ! in_array($requirement, self::VALID_REQUIREMENTS, true)
            || ! is_array($satisfyingStates)
            || $satisfyingStates === []
            || array_diff($satisfyingStates, CarePlanSignOff::ATTESTATION_STATES) !== []
        ) {
            $this->denyEligibility();
        }

        if (
            ($requirement === 'not_required' && $satisfyingStates !== [CarePlanSignOff::STATE_NOT_REQUIRED])
            || ($requirement === 'eligible_attestation'
                && array_diff($satisfyingStates, [
                    CarePlanSignOff::STATE_DIRECT_AUTHENTICATED,
                    CarePlanSignOff::STATE_WITNESSED,
                    CarePlanSignOff::STATE_AUTHORISED_REPRESENTATIVE,
                ]) !== [])
        ) {
            $this->denyEligibility();
        }

        return [
            'version' => 1,
            'requirement' => $requirement,
            'satisfying_states' => array_values($satisfyingStates),
            'governance_review_required' => (bool) ($policy['governance_review_required'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    private function normaliseContract(
        CarePlan $plan,
        User $actor,
        array $data,
        string $state,
        string $source,
        array $policy,
    ): array {
        return match ($state) {
            CarePlanSignOff::STATE_DIRECT_AUTHENTICATED => $this->directContract($plan, $actor, $data, $source),
            CarePlanSignOff::STATE_WITNESSED => $this->witnessedContract($plan, $actor, $data, $source),
            CarePlanSignOff::STATE_AUTHORISED_REPRESENTATIVE => $this->representativeContract($plan, $actor, $data, $source),
            CarePlanSignOff::STATE_DECLINED => $this->declinedContract($plan, $actor, $data, $source),
            CarePlanSignOff::STATE_UNAVAILABLE => $this->unavailableContract($plan, $actor, $data, $source),
            CarePlanSignOff::STATE_NOT_REQUIRED => $this->notRequiredContract($plan, $actor, $data, $source, $policy),
            CarePlanSignOff::STATE_RECORDED_PROXY => $this->recordedProxyContract($plan, $actor, $data, $source),
            default => $this->denyEligibility(),
        };
    }

    /** @return array<string, mixed> */
    private function directContract(CarePlan $plan, User $actor, array $data, string $source): array
    {
        $now = now();

        if ($source === self::SOURCE_PORTAL) {
            $pivot = DB::table('client_portal_users')
                ->where('client_id', $plan->client_id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if (! $pivot || ! in_array($pivot->relation, ['client', 'self'], true)) {
                $this->denyEligibility();
            }

            return $this->baseContract($data, [
                'attestation_state' => CarePlanSignOff::STATE_DIRECT_AUTHENTICATED,
                'signer_type' => 'client',
                'signer_user_id' => $actor->id,
                'signer_client_id' => $plan->client_id,
                'party_role' => 'client',
                'party_name' => $actor->name,
                'relationship' => 'Self',
                'agreed_on' => $now->toDateString(),
                'method' => 'portal',
                'authority_basis' => 'authenticated_client_portal',
                'evidence_type' => 'authenticated_session',
                'signer_asserted_at' => $now,
                'gate_satisfying' => true,
                'signer_fingerprint' => hash(self::DIGEST_ALGORITHM, 'client:'.$plan->client_id),
                'identity_provenance' => [
                    'source' => self::SOURCE_PORTAL,
                    'identity_source' => 'authenticated_client_portal_membership',
                    'signer_user_id' => $actor->id,
                    'signer_client_id' => $plan->client_id,
                    'recorder_user_id' => $actor->id,
                    'governance_review_required' => true,
                ],
            ]);
        }

        if ($source !== self::SOURCE_OPERATIONS || (int) ($data['signer_user_id'] ?? $actor->id) !== $actor->id) {
            $this->denyEligibility();
        }

        $credential = StaffCredential::query()
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->find($data['clinical_credential_id'] ?? null);

        if (
            ! $credential
            || ! in_array(
                str_replace([' ', '-'], '_', mb_strtolower(trim((string) $credential->type))),
                self::CLINICAL_CREDENTIAL_TYPES,
                true,
            )
            || $credential->issued_at?->isFuture()
            || ($credential->expires_at !== null && $credential->expires_at->isPast())
        ) {
            $this->denyEligibility();
        }

        return $this->baseContract($data, [
            'attestation_state' => CarePlanSignOff::STATE_DIRECT_AUTHENTICATED,
            'signer_type' => 'clinician',
            'signer_user_id' => $actor->id,
            'clinical_credential_id' => $credential->id,
            'party_role' => 'clinician',
            'party_name' => $actor->name,
            'agreed_on' => $now->toDateString(),
            'method' => $data['method'] ?? 'in_person',
            'authority_basis' => 'current_clinical_credential',
            'evidence_type' => 'authenticated_session',
            'signer_asserted_at' => $now,
            'gate_satisfying' => true,
            'signer_fingerprint' => hash(self::DIGEST_ALGORITHM, 'user:'.$actor->id),
            'identity_provenance' => [
                'source' => self::SOURCE_OPERATIONS,
                'identity_source' => 'authenticated_staff_session',
                'signer_user_id' => $actor->id,
                'recorder_user_id' => $actor->id,
                'clinical_credential' => [
                    'id' => $credential->id,
                    'type' => $credential->type,
                    'issuer' => $credential->issuer,
                    'issued_at' => $credential->issued_at?->toDateString(),
                    'expires_at' => $credential->expires_at?->toDateString(),
                ],
                'governance_review_required' => true,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function witnessedContract(CarePlan $plan, User $actor, array $data, string $source): array
    {
        if (
            $source !== self::SOURCE_OPERATIONS
            || (int) ($data['signer_client_id'] ?? 0) !== (int) $plan->client_id
        ) {
            $this->denyEligibility();
        }
        $this->assertWitnessEvidence($data);

        return $this->baseContract($data, [
            'attestation_state' => CarePlanSignOff::STATE_WITNESSED,
            'signer_type' => 'client',
            'signer_client_id' => $plan->client_id,
            'party_role' => 'client',
            'party_name' => $plan->client?->full_name,
            'relationship' => 'Self',
            'authority_basis' => 'identified_client_witnessed_by_authenticated_staff',
            'evidence_type' => $data['evidence_type'],
            'evidence_reference' => $data['evidence_reference'],
            'signer_asserted_at' => CarbonImmutable::parse($data['agreed_on'])->startOfDay(),
            'witnessed_by' => $actor->id,
            'witnessed_at' => now(),
            'gate_satisfying' => true,
            'signer_fingerprint' => hash(self::DIGEST_ALGORITHM, 'client:'.$plan->client_id),
            'identity_provenance' => [
                'source' => self::SOURCE_OPERATIONS,
                'identity_source' => 'canonical_client_record',
                'signer_client_id' => $plan->client_id,
                'recorder_user_id' => $actor->id,
                'witness_user_id' => $actor->id,
                'witness_declaration' => true,
                'governance_review_required' => true,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function representativeContract(CarePlan $plan, User $actor, array $data, string $source): array
    {
        $authority = NextOfKin::query()
            ->where('client_id', $plan->client_id)
            ->with('user:id,name')
            ->lockForUpdate()
            ->find($data['authority_next_of_kin_id'] ?? null);
        $authorityBasis = (string) ($data['authority_basis'] ?? '');

        if (
            ! $authority
            || ! $authority->user
            || ! in_array($authorityBasis, ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS, true)
            || ! $authority->hasVerifiedLegalAuthority($authorityBasis)
            || (isset($data['signer_user_id']) && (int) $data['signer_user_id'] !== (int) $authority->user_id)
        ) {
            $this->denyEligibility();
        }

        $capacityEvidence = ClientConsent::query()
            ->where('client_id', $plan->client_id)
            ->lockForUpdate()
            ->find($data['capacity_evidence_consent_id'] ?? null);

        if (
            ! $capacityEvidence
            || ! $capacityEvidence->capacity_assessed
            || $capacityEvidence->capacity_outcome !== 'lacks_capacity'
            || $capacityEvidence->capacity_assessor_id === null
            || $capacityEvidence->capacity_assessed_at === null
        ) {
            $this->denyEligibility();
        }

        if ($source === self::SOURCE_PORTAL) {
            if ($actor->id !== $authority->user_id) {
                $this->denyEligibility();
            }
            $evidenceType = 'authenticated_session';
            $evidenceReference = null;
            $witnessedBy = null;
            $witnessedAt = null;
        } elseif ($source === self::SOURCE_OPERATIONS) {
            $this->assertWitnessEvidence($data);
            $evidenceType = $data['evidence_type'];
            $evidenceReference = $data['evidence_reference'];
            $witnessedBy = $actor->id;
            $witnessedAt = now();
        } else {
            $this->denyEligibility();
        }

        return $this->baseContract($data, [
            'attestation_state' => CarePlanSignOff::STATE_AUTHORISED_REPRESENTATIVE,
            'signer_type' => 'authorised_representative',
            'signer_user_id' => $authority->user_id,
            'authority_next_of_kin_id' => $authority->id,
            'capacity_evidence_consent_id' => $capacityEvidence->id,
            'party_role' => 'eor_guardian',
            'party_name' => $authority->user->name,
            'relationship' => $authority->relationship,
            'agreed_on' => $source === self::SOURCE_PORTAL ? now()->toDateString() : $data['agreed_on'],
            'method' => $source === self::SOURCE_PORTAL ? 'portal' : ($data['method'] ?? null),
            'authority_basis' => $authorityBasis,
            'capacity_basis' => 'recorded_lacks_capacity',
            'evidence_type' => $evidenceType,
            'evidence_reference' => $evidenceReference,
            'signer_asserted_at' => $source === self::SOURCE_PORTAL
                ? now()
                : CarbonImmutable::parse($data['agreed_on'])->startOfDay(),
            'witnessed_by' => $witnessedBy,
            'witnessed_at' => $witnessedAt,
            'gate_satisfying' => true,
            'signer_fingerprint' => hash(self::DIGEST_ALGORITHM, 'next-of-kin:'.$authority->id),
            'identity_provenance' => [
                'source' => $source,
                'identity_source' => $source === self::SOURCE_PORTAL
                    ? 'authenticated_representative_portal_membership'
                    : 'verified_next_of_kin_record_with_staff_witness',
                'signer_user_id' => $authority->user_id,
                'recorder_user_id' => $actor->id,
                'witness_user_id' => $witnessedBy,
                'authority_snapshot' => [
                    'next_of_kin_id' => $authority->id,
                    'type' => $authority->legal_authority_type,
                    'verified_at' => $authority->legal_authority_verified_at?->toISOString(),
                    'verified_by_user_id' => $authority->legal_authority_verified_by_user_id,
                    'expires_at' => $authority->legal_authority_expires_at?->toISOString(),
                ],
                'capacity_snapshot' => [
                    'client_consent_id' => $capacityEvidence->id,
                    'outcome' => $capacityEvidence->capacity_outcome,
                    'assessor_user_id' => $capacityEvidence->capacity_assessor_id,
                    'assessed_at' => $capacityEvidence->capacity_assessed_at?->toISOString(),
                    'evidence_type' => $capacityEvidence->evidence_type,
                ],
                'governance_review_required' => true,
                'legal_determination' => 'not_made_by_care_plan_workflow',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function declinedContract(CarePlan $plan, User $actor, array $data, string $source): array
    {
        if (blank($data['outcome_reason'] ?? null)) {
            $this->denyEligibility();
        }

        $contract = isset($data['authority_next_of_kin_id'])
            ? $this->representativeContract($plan, $actor, $data, $source)
            : ($source === self::SOURCE_PORTAL
                ? $this->directContract($plan, $actor, $data, $source)
                : $this->witnessedContract($plan, $actor, $data, $source));

        $contract['attestation_state'] = CarePlanSignOff::STATE_DECLINED;
        $contract['gate_satisfying'] = false;
        $contract['outcome_reason'] = $data['outcome_reason'];

        return $contract;
    }

    /** @return array<string, mixed> */
    private function unavailableContract(CarePlan $plan, User $actor, array $data, string $source): array
    {
        if ($source !== self::SOURCE_OPERATIONS || blank($data['outcome_reason'] ?? null)) {
            $this->denyEligibility();
        }
        $this->assertWitnessEvidence($data);

        if ((int) ($data['signer_client_id'] ?? 0) !== (int) $plan->client_id) {
            $this->denyEligibility();
        }

        return $this->baseContract($data, [
            'attestation_state' => CarePlanSignOff::STATE_UNAVAILABLE,
            'signer_type' => 'client',
            'signer_client_id' => $plan->client_id,
            'party_role' => 'client',
            'party_name' => $plan->client?->full_name,
            'relationship' => 'Self',
            'authority_basis' => 'identified_client_unavailable',
            'evidence_type' => $data['evidence_type'],
            'evidence_reference' => $data['evidence_reference'],
            'outcome_reason' => $data['outcome_reason'],
            'witnessed_by' => $actor->id,
            'witnessed_at' => now(),
            'gate_satisfying' => false,
            'signer_fingerprint' => hash(self::DIGEST_ALGORITHM, 'client:'.$plan->client_id),
            'identity_provenance' => [
                'source' => self::SOURCE_OPERATIONS,
                'identity_source' => 'canonical_client_record',
                'signer_client_id' => $plan->client_id,
                'recorder_user_id' => $actor->id,
                'state_observed_by_user_id' => $actor->id,
                'governance_review_required' => true,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function notRequiredContract(
        CarePlan $plan,
        User $actor,
        array $data,
        string $source,
        array $policy,
    ): array {
        if (
            $source !== self::SOURCE_OPERATIONS
            || blank($data['outcome_reason'] ?? null)
            || blank($data['evidence_reference'] ?? null)
            || ($data['evidence_type'] ?? null) !== 'governance_record'
        ) {
            $this->denyEligibility();
        }

        return $this->baseContract($data, [
            'attestation_state' => CarePlanSignOff::STATE_NOT_REQUIRED,
            'signer_type' => 'none',
            'party_role' => 'other',
            'party_name' => 'Not required by recorded policy',
            'authority_basis' => 'care_plan_attestation_policy',
            'evidence_type' => 'governance_record',
            'evidence_reference' => $data['evidence_reference'],
            'outcome_reason' => $data['outcome_reason'],
            'gate_satisfying' => $policy['requirement'] === 'not_required',
            'signer_fingerprint' => hash(self::DIGEST_ALGORITHM, 'policy-not-required:'.$plan->id),
            'identity_provenance' => [
                'source' => self::SOURCE_OPERATIONS,
                'identity_source' => 'explicit_care_plan_policy',
                'recorder_user_id' => $actor->id,
                'governance_review_required' => true,
                'legal_determination' => 'not_made_by_care_plan_workflow',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function recordedProxyContract(CarePlan $plan, User $actor, array $data, string $source): array
    {
        if ($source !== self::SOURCE_OPERATIONS || blank($data['party_name'] ?? null)) {
            $this->denyEligibility();
        }

        $partyRole = (string) $data['party_role'];
        $partyName = trim((string) $data['party_name']);

        return $this->baseContract($data, [
            'attestation_state' => CarePlanSignOff::STATE_RECORDED_PROXY,
            'signer_type' => 'named_proxy',
            'party_role' => $partyRole,
            'party_name' => $partyName,
            'authority_basis' => 'staff_recorded_information_only',
            'gate_satisfying' => false,
            'signer_fingerprint' => hash(
                self::DIGEST_ALGORITHM,
                'named-proxy:'.mb_strtolower($partyRole.'|'.$partyName),
            ),
            'identity_provenance' => [
                'source' => self::SOURCE_OPERATIONS,
                'identity_source' => 'staff_entered_label_only',
                'recorder_user_id' => $actor->id,
                'direct_assent' => false,
                'gate_satisfying' => false,
                'governance_review_required' => true,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function baseContract(array $data, array $authoritative): array
    {
        return array_merge([
            'party_role' => $data['party_role'],
            'party_name' => $data['party_name'] ?? null,
            'relationship' => $data['relationship'] ?? null,
            'agreed_on' => $data['agreed_on'],
            'method' => $data['method'] ?? null,
            'acknowledgement' => $data['acknowledgement'] ?? null,
            'outcome_reason' => $data['outcome_reason'] ?? null,
            'gate_satisfying' => false,
        ], $authoritative);
    }

    private function assertActorContext(CarePlan $plan, User $actor, string $source): void
    {
        if ($source === self::SOURCE_OPERATIONS && $actor->can('update', $plan)) {
            return;
        }

        if (
            $source === self::SOURCE_PORTAL
            && $plan->client
            && $actor->canAccessClientPortal($plan->client)
        ) {
            return;
        }

        abort(404);
    }

    private function assertWitnessEvidence(array $data): void
    {
        if (
            ! filter_var($data['witness_declaration'] ?? false, FILTER_VALIDATE_BOOL)
            || ! in_array($data['evidence_type'] ?? null, self::EVIDENCE_TYPES, true)
            || ($data['evidence_type'] ?? null) === 'governance_record'
            || blank($data['evidence_reference'] ?? null)
        ) {
            $this->denyEligibility();
        }
    }

    private function supersedeStaleAttestations(CarePlan $plan, string $currentDigest): void
    {
        CarePlanSignOff::query()
            ->where('care_plan_id', $plan->id)
            ->whereNull('revoked_at')
            ->whereNull('superseded_at')
            ->whereNotNull('plan_version_digest')
            ->where('plan_version_digest', '!=', $currentDigest)
            ->update([
                'active_identity_key' => null,
                'superseded_at' => now(),
                'superseded_reason' => 'Care-plan content changed after this evidence was recorded.',
                'updated_at' => now(),
            ]);
    }

    private function policyIsSatisfied(array $policy, $attestations): bool
    {
        return $attestations->contains(fn (CarePlanSignOff $attestation): bool => $attestation->gate_satisfying
            && in_array($attestation->attestation_state, $policy['satisfying_states'], true)
        );
    }

    /** @param array<string, mixed>|null $content */
    private function hasStructuredDomains(?array $content): bool
    {
        return collect($content['domains'] ?? [])
            ->contains(fn ($domain): bool => is_array($domain) && filled($domain['label'] ?? null));
    }

    private function hashPayload(array $payload): string
    {
        return hash(
            self::DIGEST_ALGORITHM,
            json_encode(
                $this->canonicalise($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalise($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalise($item);
        }

        return $value;
    }

    private function denyEligibility(): never
    {
        throw ValidationException::withMessages([
            'attestation' => 'The signer or evidence is not eligible for this care plan.',
        ]);
    }
}
