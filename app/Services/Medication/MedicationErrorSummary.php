<?php

namespace App\Services\Medication;

use App\Models\MedicationError;

/** Safe outside the permissioned error record: never includes user-entered text. */
final class MedicationErrorSummary
{
    public static function for(MedicationError $error): string
    {
        $reach = match ($error->reached_client) {
            'no' => 'Did not reach the person (near miss)',
            'yes' => 'Reached the person',
            default => 'Reach not known yet',
        };

        return 'Medication error '.($error->reference_number ?: 'MED-'.$error->id).'. '.$reach.'. Details are held in the medication error record.';
    }
}
