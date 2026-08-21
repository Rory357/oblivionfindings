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
        string $reason,
        ?int $submittedClientId = null,
        ?Carbon $ceasedAt = null,
    ): ClientMedication {
        abort_unless($performer->canDo('medications.orders.manage'), 403);

        $reason = trim($reason);
        $ceasedAt ??= now();

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required and must not exceed 255 characters.',
            ]);
        }

        return $this->scope->forMedication(
            $performer,
            $submittedMedication,
            $ceasedAt,
            function (MedicationScopeDecision $decision) use ($reason, $ceasedAt): ClientMedication {
                $medication = $decision->medication;

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
                    'end_date' => $ceasedAt->toDateString(),
                    'ceased_reason' => $reason,
                    'ceased_at' => $ceasedAt,
                    'ceased_by' => $decision->performer->id,
                    'version' => $nextVersion,
                ])->save();

                $version = MedicationOrderVersion::query()->create([
                    'client_medication_id' => $medication->id,
                    'client_id' => $decision->client->id,
                    'version_number' => $nextVersion,
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
                    'ceased_at' => $ceasedAt,
                    'ceased_reason' => $reason,
                    'state' => 'ceased',
                    'paused_at' => $medication->paused_at,
                    'active' => false,
                    'change_reason' => mb_substr('Medication discontinued: '.$reason, 0, 255),
                    'changed_by' => $decision->performer->id,
                    'changed_at' => $ceasedAt,
                ]);

                AuditLogger::logOrFail('medication_order.discontinued', $medication, [
                    'actor_id' => $decision->performer->id,
                    'client_id' => $decision->client->id,
                    'site_id' => $decision->siteId,
                    'medication_order_version_id' => $version->id,
                    'version_number' => $nextVersion,
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
        );
    }
}
