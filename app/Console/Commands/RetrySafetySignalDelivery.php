<?php

namespace App\Console\Commands;

use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use Illuminate\Console\Command;
use Throwable;

class RetrySafetySignalDelivery extends Command
{
    protected $signature = 'safety-signals:retry
        {source : fleet, shift, device, or incident}
        {outbox : Outbox row id}';

    protected $description = 'Safely replay one failed safety-signal delivery without duplicating its final alert';

    public function handle(SafetySignalDeliveryRecoveryService $recovery): int
    {
        try {
            $recovery->retry((string) $this->argument('source'), (int) $this->argument('outbox'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Safety-signal delivery replay queued.');

        return self::SUCCESS;
    }
}
