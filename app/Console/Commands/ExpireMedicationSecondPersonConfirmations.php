<?php

namespace App\Console\Commands;

use App\Services\Medication\ForgottenWitnessPinService;
use Illuminate\Console\Command;

final class ExpireMedicationSecondPersonConfirmations extends Command
{
    protected $signature = 'emar:expire-second-person-confirmations';

    protected $description = 'Expire named colleague confirmations and retain one canonical lead follow-up';

    public function handle(ForgottenWitnessPinService $confirmations): int
    {
        $expired = $confirmations->expireDue();
        if ($expired > 0) {
            $this->info($expired.' second-person confirmation(s) expired.');
        }

        return self::SUCCESS;
    }
}
