<?php

namespace App\Console\Commands;

use App\Domain\Governance\Jobs\SendBoardDigest as SendBoardDigestJob;
use Illuminate\Console\Command;

class SendBoardDigest extends Command
{
    protected $signature = 'governance:send-digest';
    protected $description = 'Send weekly board digest emails';

    public function handle(): int
    {
        $this->info('Sending board digests...');

        SendBoardDigestJob::dispatch();

        $this->info('Board digest jobs dispatched.');

        return self::SUCCESS;
    }
}
