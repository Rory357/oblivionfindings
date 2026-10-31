<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptOutputs;
use Illuminate\Console\Command;

final class PurgeEndpointScriptOutputs extends Command
{
    protected $signature = 'endpoints:purge-script-outputs {--batch=100}';

    protected $description = 'Erase expired private script content while preserving immutable receipts';

    public function handle(EndpointScriptOutputs $outputs): int
    {
        $batch = (string) $this->option('batch');
        if (! preg_match('/\A[1-9][0-9]{0,2}\z/', $batch) || (int) $batch > 500) {
            $this->error('Batch must be between 1 and 500.');

            return self::INVALID;
        }
        $this->info('Expired script outputs removed: '.$outputs->purge((int) $batch));

        return self::SUCCESS;
    }
}
