<?php

namespace App\Observers;

use App\Models\Client;
use App\Services\Medication\Reviews\MedicationReviewWorkflow;

final class MedicationReviewClientObserver
{
    public function updated(Client $client): void
    {
        if ($client->wasChanged('status') && in_array($client->status, ['inactive', 'discharged', 'deceased'], true)) {
            app(MedicationReviewWorkflow::class)->closeForDeparture($client);
        }
    }
}
