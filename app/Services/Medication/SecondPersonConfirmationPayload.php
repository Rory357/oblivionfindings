<?php

namespace App\Services\Medication;

use App\Models\ClientMedicationAdministration;

/** The safe read contract; eligibility evidence and confirmation authority stay private. */
class SecondPersonConfirmationPayload
{
    public const RELATION = 'secondPersonConfirmation.nominatedUser:id,name';

    public function forAdministration(?ClientMedicationAdministration $administration): array
    {
        if ($administration?->witnessed_by !== null) {
            // List readers load actual witnesses in one batch. Never infer a
            // verified witness from the nominated (still unverified) colleague.
            $administration->loadMissing('witnessedBy:id,name');
        }
        $confirmation = null;
        if ($administration && in_array($administration->witness_method, [
            ForgottenWitnessPinService::METHOD,
            ForgottenWitnessPinService::CONFIRMED_METHOD,
        ], true)) {
            // List readers eager load this relation once for their entire result set.
            // A recording/replay response contains only one administration.
            $administration->loadMissing(self::RELATION);
            $nomination = $administration->secondPersonConfirmation;
            if ($nomination) {
                $confirmation = [
                    'id' => (int) $nomination->id,
                    'status' => $nomination->status,
                    'nominated_name' => $nomination->nominatedUser?->name,
                    'due_at' => $nomination->due_at?->toIso8601String(),
                ];
            }
        }

        return [
            'witness' => $administration?->witnessedBy?->name,
            'second_person_kind' => $administration?->second_person_kind,
            'second_person_status' => $administration?->second_person_status,
            'witness_method' => $administration?->witness_method,
            'second_person_confirmation' => $confirmation,
        ];
    }
}
