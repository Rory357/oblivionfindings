<?php

namespace App\Domain\SecurityDevices\Console;

use App\Domain\SecurityDevices\Endpoints\Services\IntuneVisibilitySync;
use Illuminate\Console\Command;

class SyncIntuneVisibility extends Command
{
    protected $signature = 'endpoints:intune-sync';

    protected $description = 'Refresh read-only Intune observations for explicitly linked canonical devices';

    public function handle(IntuneVisibilitySync $sync): int
    {
        try {
            $this->info('Updated '.$sync->sync().' linked Intune device observations.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Intune refresh failed. Check the read-only connection and retry; previous observations were retained.');

            return self::FAILURE;
        }
    }
}
