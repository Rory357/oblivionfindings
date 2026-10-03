<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\User;

/** Unstructured legacy chart text has no reliable medicine ownership link. */
final class MedicationRecordSafetyPrivacy
{
    public function hidesUnstructuredText(User $actor, Client $client): bool
    {
        return ! $actor->canDo('medications.controlled.view') && ClientMedication::withTrashed()->where('client_id', $client->id)->where('controlled_drug', true)->exists();
    }
}
