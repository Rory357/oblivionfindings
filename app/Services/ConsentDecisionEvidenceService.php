<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ConsentRequest;
use App\Models\NextOfKin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Owns the evidence boundary for decision-specific substituted consent.
 * Relationship and legal authority identify who may decide; neither is
 * evidence that the client lacks capacity or that a best-interests process ran.
 */
class ConsentDecisionEvidenceService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function capture(
        array $data,
        User $requester,
        Client $client,
        User $recipient,
        NextOfKin $authority,
    ): array {
        if (
            ! $requester->canDo('consents.request')
            || ! $requester->canDo('consents.manage')
            || ! $requester->can('view', $client)
        ) {
            throw ValidationException::withMessages([
                'capacity_assessment' => 'Current consent-management authority is required to record capacity evidence.',
            ]);
        }

        if ($requester->is($recipient)) {
            throw ValidationException::withMessages([
                'capacity_assessment' => 'The capacity assessor and substitute decision-maker must be different people.',
            ]);
        }

        $capacityOutcome = $this->requiredString($data, 'capacity_outcome', 32);
        if ($capacityOutcome !== 'lacks_capacity') {
            throw ValidationException::withMessages([
                'capacity_outcome' => 'Substituted consent requires an explicit decision-specific lacks-capacity assessment.',
            ]);
        }

        $assessedAt = $this->requiredDate($data, 'capacity_assessed_at');
        $assessmentExpiresAt = $this->requiredDate($data, 'capacity_assessment_expires_at');
        if ($assessedAt->isFuture()) {
            throw ValidationException::withMessages([
                'capacity_assessed_at' => 'The capacity assessment time cannot be in the future.',
            ]);
        }
        if (! $assessmentExpiresAt->isAfter($assessedAt) || ! $assessmentExpiresAt->isFuture()) {
            throw ValidationException::withMessages([
                'capacity_assessment_expires_at' => 'The capacity assessment must remain current for the consent decision.',
            ]);
        }

        $consultees = collect($data['best_interests_consultees'] ?? null)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
        if ($consultees === []) {
            throw ValidationException::withMessages([
                'best_interests_consultees' => 'Record at least one participant in the decision-specific best-interests process.',
            ]);
        }

        $captured = [
            'capacity_outcome' => $capacityOutcome,
            'capacity_assessor_user_id' => $requester->id,
            'capacity_assessed_at' => $assessedAt,
            'capacity_assessment_expires_at' => $assessmentExpiresAt,
            'capacity_assessment_reason' => $this->requiredString($data, 'capacity_assessment_reason', 2000, 20),
            'capacity_evidence_type' => $this->requiredString($data, 'capacity_evidence_type', 80),
            'capacity_evidence_reference' => $this->requiredString($data, 'capacity_evidence_reference', 255),
            'best_interests_process_reason' => $this->requiredString($data, 'best_interests_process_reason', 2000, 20),
            'best_interests_evidence_type' => $this->requiredString($data, 'best_interests_evidence_type', 80),
            'best_interests_evidence_reference' => $this->requiredString($data, 'best_interests_evidence_reference', 255),
            'best_interests_consultees' => $consultees,
            'decision_evidence_recorded_by_user_id' => $requester->id,
            'decision_evidence_recorded_at' => now(),
            'decision_evidence_accepted_by_user_id' => null,
            'decision_evidence_accepted_at' => null,
            'decision_evidence_revoked_by_user_id' => null,
            'decision_evidence_revoked_at' => null,
            'decision_evidence_revocation_reason' => null,
        ];

        $captured['decision_scope_digest'] = $this->scopeDigest(
            $data,
            $captured,
            $client,
            $recipient,
            $authority,
        );

        return $captured;
    }

    public function assertCurrent(
        ConsentRequest $request,
        User $requester,
        User $recipient,
        Client $client,
        NextOfKin $authority,
    ): void {
        if (
            $request->decision_evidence_revoked_at !== null
            || $request->decision_evidence_accepted_at !== null
            || $request->capacity_outcome !== 'lacks_capacity'
            || $request->capacity_assessor_user_id !== $requester->id
            || $request->decision_evidence_recorded_by_user_id !== $requester->id
            || $requester->is($recipient)
            || ! $requester->canDo('consents.request')
            || ! $requester->canDo('consents.manage')
            || ! $requester->can('view', $client)
            || ! $request->capacity_assessment_expires_at?->isFuture()
        ) {
            throw new ConflictHttpException('Decision-specific capacity evidence is no longer current.');
        }

        $evidence = [
            'capacity_outcome' => $request->capacity_outcome,
            'capacity_assessor_user_id' => $request->capacity_assessor_user_id,
            'capacity_assessed_at' => $request->capacity_assessed_at,
            'capacity_assessment_expires_at' => $request->capacity_assessment_expires_at,
            'capacity_assessment_reason' => $request->capacity_assessment_reason,
            'capacity_evidence_type' => $request->capacity_evidence_type,
            'capacity_evidence_reference' => $request->capacity_evidence_reference,
            'best_interests_process_reason' => $request->best_interests_process_reason,
            'best_interests_evidence_type' => $request->best_interests_evidence_type,
            'best_interests_evidence_reference' => $request->best_interests_evidence_reference,
            'best_interests_consultees' => $request->best_interests_consultees,
            'decision_evidence_recorded_by_user_id' => $request->decision_evidence_recorded_by_user_id,
            'decision_evidence_recorded_at' => $request->decision_evidence_recorded_at,
        ];

        $currentDigest = $this->scopeDigest(
            $request->getAttributes(),
            $evidence,
            $client,
            $recipient,
            $authority,
        );

        if (
            ! is_string($request->decision_scope_digest)
            || ! hash_equals($request->decision_scope_digest, $currentDigest)
        ) {
            throw new ConflictHttpException('The decision scope or evidence changed after it was recorded.');
        }
    }

    /** @return array<string, mixed> */
    public function provenance(ConsentRequest $request, User $recipient, string $responseReason, CarbonImmutable $acceptedAt): array
    {
        return [
            'scope_digest' => $request->decision_scope_digest,
            'capacity' => [
                'outcome' => $request->capacity_outcome,
                'assessor_user_id' => $request->capacity_assessor_user_id,
                'assessed_at' => $request->capacity_assessed_at?->toIso8601String(),
                'expires_at' => $request->capacity_assessment_expires_at?->toIso8601String(),
                'reason' => $request->capacity_assessment_reason,
                'evidence_type' => $request->capacity_evidence_type,
                'evidence_reference' => $request->capacity_evidence_reference,
            ],
            'authority' => [
                'next_of_kin_id' => $request->authority_next_of_kin_id,
                'type' => $request->recipient_relationship,
            ],
            'best_interests_process' => [
                'reason' => $request->best_interests_process_reason,
                'evidence_type' => $request->best_interests_evidence_type,
                'evidence_reference' => $request->best_interests_evidence_reference,
                'consultees' => $request->best_interests_consultees,
            ],
            'recorded_by_user_id' => $request->decision_evidence_recorded_by_user_id,
            'recorded_at' => $request->decision_evidence_recorded_at?->toIso8601String(),
            'accepted_by_user_id' => $recipient->id,
            'accepted_at' => $acceptedAt->toIso8601String(),
            'accepted_reason' => $responseReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $evidence
     */
    private function scopeDigest(
        array $data,
        array $evidence,
        Client $client,
        User $recipient,
        NextOfKin $authority,
    ): string {
        return hash('sha256', json_encode([
            'version' => 1,
            'client_id' => (int) $client->id,
            'site_id' => $client->site_id === null ? null : (int) $client->site_id,
            'consent_type_id' => (int) ($data['consent_type_id'] ?? 0),
            'recipient_user_id' => (int) $recipient->id,
            'recipient_relationship' => $data['recipient_relationship'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'data_scope' => $data['data_scope'] ?? null,
            'retention_period_days' => filled($data['retention_period_days'] ?? null)
                ? (int) $data['retention_period_days']
                : null,
            'withdrawal_method_text' => $data['withdrawal_method_text'] ?? null,
            'triggering_subject_type' => $data['triggering_subject_type'] ?? null,
            'triggering_subject_id' => filled($data['triggering_subject_id'] ?? null)
                ? (int) $data['triggering_subject_id']
                : null,
            'authority' => [
                'id' => (int) $authority->id,
                'type' => $authority->legal_authority_type,
                'verified_at' => $this->iso($authority->legal_authority_verified_at),
                'verified_by_user_id' => (int) $authority->legal_authority_verified_by_user_id,
                'expires_at' => $this->iso($authority->legal_authority_expires_at),
                'updated_at' => $this->iso($authority->updated_at),
            ],
            'capacity' => [
                'outcome' => $evidence['capacity_outcome'] ?? null,
                'assessor_user_id' => filled($evidence['capacity_assessor_user_id'] ?? null)
                    ? (int) $evidence['capacity_assessor_user_id']
                    : null,
                'assessed_at' => $this->iso($evidence['capacity_assessed_at'] ?? null),
                'expires_at' => $this->iso($evidence['capacity_assessment_expires_at'] ?? null),
                'reason' => $evidence['capacity_assessment_reason'] ?? null,
                'evidence_type' => $evidence['capacity_evidence_type'] ?? null,
                'evidence_reference' => $evidence['capacity_evidence_reference'] ?? null,
            ],
            'best_interests_process' => [
                'reason' => $evidence['best_interests_process_reason'] ?? null,
                'evidence_type' => $evidence['best_interests_evidence_type'] ?? null,
                'evidence_reference' => $evidence['best_interests_evidence_reference'] ?? null,
                'consultees' => $evidence['best_interests_consultees'] ?? null,
            ],
            'recorded_by_user_id' => filled($evidence['decision_evidence_recorded_by_user_id'] ?? null)
                ? (int) $evidence['decision_evidence_recorded_by_user_id']
                : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, int $maximum, int $minimum = 1): string
    {
        $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum) {
            throw ValidationException::withMessages([
                $key => "Record valid {$key} evidence.",
            ]);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredDate(array $data, string $key): CarbonImmutable
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) && ! ($value instanceof \DateTimeInterface)) {
            throw ValidationException::withMessages([
                $key => "Record a valid {$key} time.",
            ]);
        }

        try {
            $parsed = $value instanceof \DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value);

            return $parsed->setTimezone((string) config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $key => "Record a valid {$key} time.",
            ]);
        }
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z')
            : null;
    }
}
