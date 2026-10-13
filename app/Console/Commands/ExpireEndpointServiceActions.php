<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareCommands;
use Illuminate\Console\Command;

final class ExpireEndpointServiceActions extends Command
{
    protected $signature = 'endpoints:expire-service-actions {--batch=100}';

    protected $description = 'Expire unclaimed native service attempts and retain claimed attempts as uncertain without redispatch.';

    public function handle(EndpointServiceCommands $commands): int
    {
        $this->info('Updated '.$commands->expire((int) $this->option('batch')).' expired or uncertain service attempts.');

        $this->info('Updated '.app(EndpointRestartCommands::class)->expire((int) $this->option('batch')).' expired or uncertain restart attempts.');

        $this->info('Updated '.app(EndpointSoftwareCommands::class)->expire((int) $this->option('batch')).' expired or uncertain software attempts.');

        return self::SUCCESS;
    }
}
