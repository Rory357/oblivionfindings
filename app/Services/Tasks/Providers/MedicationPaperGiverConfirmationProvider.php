<?php

namespace App\Services\Tasks\Providers;

class MedicationPaperGiverConfirmationProvider extends MedicationPaperConfirmationProvider
{
    protected function kind(): string
    {
        return 'giver';
    }
}
