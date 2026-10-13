<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSessions;
use Illuminate\Console\Command;

final class ExpireEndpointRemoteSessions extends Command
{
    protected $signature = 'endpoints:expire-remote-sessions';

    protected $description = 'Record expiry of abandoned remote-support requests and leases';

    public function handle(EndpointRemoteSessions $sessions): int
    {
        $this->info('Expired remote sessions: '.$sessions->expireStale());

        return self::SUCCESS;
    }
}
