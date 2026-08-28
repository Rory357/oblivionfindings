<?php

namespace App\Services\Medication;

use App\Models\ClientMedication;
use App\Models\MedicationOrderVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single write boundary for ending a medication order.
 *
 * MedicationScopeDecisionService owns the transaction and locks the canonical
 * order, client, Site and current assignment/break-glass evidence before this
 * callback runs. Keeping every evidence write inside that callback makes a
 * failed version, audit or break-glass write roll the cessation back as well.
 */
class MedicationOrderLifecycleService
{
    public function __construct(
        private readonly MedicationScopeDecisionService $scope,
    ) {}

    public function discontinue(
        User $performer,
        ClientMedication $submittedMedication,
        mixed $reason,
        ?int $submittedClientId = null,
        ?Carbon $ceasedAt = null,
        mixed $requestKey = null,
    ): ClientMedication {
        abort_unless($performer->canDo('medications.orders.manage'), 403);

        $authorizationAt = $ceasedAt ?? now();

        return $this->scope->forMedication(
            $performer,
            $submittedMedication,
            $authorizationAt,
            function (MedicationScopeDecision $decision) use ($reason, $ceasedAt, $requestKey): ClientMedication {
                $medication = $decision->medication;
                abort_if(
                    $medication->controlled_drug
                        && (! $decision->performer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                            || ! $decision->performer->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
                    404,
                );
                // When a request waited behind an administration lock, stamp
                // cessation only after this callback owns the canonical order.
                // That prevents a completed dose being recorded after the
                // apparent cessation time merely because the stop request began first.
                $effectiveCeasedAt = $ceasedAt ?? now();

                // Resolve and conceal the canonical order before evaluating
                // user-controlled content. A malformed reason must not reveal
                // whether a foreign medication order exists.
                if (! is_string($reason)) {
                    throw ValidationException::withMessages([
                        'reason' => 'A reason is required and must not exceed 255 characters.',
                    ]);
                }

                $reason = trim($reason);
                if ($reason === '' || mb_strlen($reason) > 255) {
                    throw ValidationException::withMessages([
                        'reason' => 'A reason is required and must not exceed 255 characters.',
                    ]);
                }

                $requestKey = $this->cessationRequestKey($requestKey, $decision, $reason);
                $payloadHash = $this->cessationPayloadHash($decision, $reason);
                $replay = MedicationOrderVersion::query()
                    ->where('cessation_request_key', $requestKey)
                    ->lockForUpdate()
                    ->first();

                if ($replay !== null) {
                    if ((int) $replay->client_medication_id !== (int) $medication->id
                        || ! is_string($replay->cessation_payload_sha256)
                        || ! hash_equals($replay->cessation_payload_sha256, $payloadHash)) {
                        throw ValidationException::withMessages([
                            'request_key' => 'This request key has already been used for a different medication action.',
                        ]);
                    }

                    if ($replay->state !== 'ceased'
                        || $medication->state !== 'ceased'
                        || $medication->ceased_at === null
                        || $replay->ceased_at === null
                        || (int) $medication->version !== (int) $replay->version_number
                        || (string) $medication->ceased_reason !== $reason
                        || (string) $replay->ceased_reason !== $reason
                        || (int) $medication->ceased_by !== (int) $decision->performer->id
                        || (int) $replay->changed_by !== (int) $decision->performer->id
                        || ! $replay->ceased_at->equalTo($medication->ceased_at)) {
                        throw new \RuntimeException('Medication cessation replay evidence is inconsistent.');
                    }

                    return $medication;
                }

                if ($medication->state === 'ceased' || $medication->ceased_at !== null) {
                    throw ValidationException::withMessages([
                        'medication' => 'The requested medication action is not available.',
                    ]);
                }

                $nextVersion = max(
                    (int) ($medication->version ?? 1),
                    (int) MedicationOrderVersion::query()
                        ->where('client_medication_id', $medication->id)
                        ->max('version_number'),
                ) + 1;

                $medication->forceFill([
                    'state' => 'ceased',
                    'active' => false,
                    'end_date' => $effectiveCeasedAt->toDateString(),
                    'ceased_reason' => $reason,
                    'ceased_at' => $effectiveCeasedAt,
                    'ceased_by' => $decision->performer->id,
                    'version' => $nextVersion,
                ])->save();

                $version = MedicationOrderVersion::query()->create([
                    'client_medication_id' => $medication->id,
                    'client_id' => $decision->client->id,
                    'version_number' => $nextVersion,
                    'cessation_request_key' => $requestKey,
                    'cessation_payload_sha256' => $payloadHash,
                    'name' => $medication->name,
                    'dosage' => $medication->dosage,
                    'dose_amount' => $medication->dose_amount,
                    'dose_unit' => $medication->dose_unit,
                    'frequency' => $medication->frequency,
                    'frequency_code' => $medication->frequency_code,
                    'dose_times' => $medication->dose_times,
                    'route' => $medication->route,
                    'form' => $medication->form,
                    'instructions' => $medication->instructions,
                    'indication' => $medication->indication,
                    'is_prn' => $medication->is_prn,
                    'prn_reason' => $medication->prn_reason,
                    'max_per_day' => $medication->max_per_day,
                    'min_hours_between_doses' => $medication->min_hours_between_doses,
                    'controlled_drug' => $medication->controlled_drug,
                    'high_risk' => $medication->high_risk,
                    'witness_required' => $medication->witness_required,
                    'prescriber' => $medication->prescriber,
                    'pharmacy' => $medication->pharmacy,
                    'start_date' => $medication->start_date,
                    'end_date' => $medication->end_date,
                    'ceased_at' => $effectiveCeasedAt,
                    'ceased_reason' => $reason,
                    'state' => 'ceased',
                    'paused_at' => $medication->paused_at,
                    'active' => false,
                    'change_reason' => mb_substr('Medication discontinued: '.$reason, 0, 255),
                    'changed_by' => $decision->performer->id,
                    'changed_at' => $effectiveCeasedAt,
                ]);

                AuditLogger::logOrFail('medication_order.discontinued', $medication, [
                    'actor_id' => $decision->performer->id,
                    'client_id' => $decision->client->id,
                    'site_id' => $decision->siteId,
                    'medication_order_version_id' => $version->id,
                    'version_number' => $nextVersion,
                    'cessation_request_key' => $requestKey,
                    'cessation_payload_sha256' => $payloadHash,
                    'ceased_at' => $effectiveCeasedAt->toIso8601String(),
                    'reason' => $reason,
                    'break_glass_access_id' => $decision->breakGlassAccess?->id,
                ]);

                $this->scope->recordBreakGlassUse(
                    $decision,
                    'ceased_medication_order',
                    'Medication '.$medication->id.' version '.$nextVersion,
                );

                return $medication;
            },
            requireAdministrable: false,
            submittedClientId: $submittedClientId,
            allowCeased: true,
        );
    }

    private function cessationRequestKey(
        mixed $submittedKey,
        MedicationScopeDecision $decision,
        string $reason,
    ): string {
        if ($submittedKey === null || $submittedKey === '') {
            return 'legacy:'.hash('sha256', implode('|', [
                (string) $decision->medication->id,
                (string) $decision->client->id,
                (string) $decision->performer->id,
                $reason,
            ]));
        }

        if (! is_string($submittedKey)) {
            throw ValidationException::withMessages([
                'request_key' => 'The medication action request key is invalid.',
            ]);
        }

        $requestKey = trim($submittedKey);
        if ($requestKey === ''
            || mb_strlen($requestKey) > 100
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/', $requestKey) !== 1) {
            throw ValidationException::withMessages([
                'request_key' => 'The medication action request key is invalid.',
            ]);
        }

        return $requestKey;
    }

    private function cessationPayloadHash(MedicationScopeDecision $decision, string $reason): string
    {
        return hash('sha256', json_encode([
            'client_id' => (int) $decision->client->id,
            'medication_id' => (int) $decision->medication->id,
            'performer_id' => (int) $decision->performer->id,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }
}
