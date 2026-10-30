<?php

namespace App\Services\Medication\ExternalClinical;

/** Portable calendar dates must not become UTC instants in a JSON packet. */
final class ExternalClinicalPrescription
{
    public static function normalise(array $payload): array
    {
        foreach (['start_date', 'end_date', 'review_date'] as $field) {
            if (($payload[$field] ?? null) instanceof \DateTimeInterface) {
                $payload[$field] = $payload[$field]->format('Y-m-d');
            }
        }
        $payload['dose_times'] = $payload['dose_times'] ?? [];

        return $payload;
    }
}
