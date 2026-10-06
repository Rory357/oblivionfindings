<?php

namespace App\Services\Tasks\Providers;

class MedicationPaperWitnessConfirmationProvider extends MedicationPaperConfirmationProvider
{
    protected function kind(): string
    {
        return 'witness';
    }
}
