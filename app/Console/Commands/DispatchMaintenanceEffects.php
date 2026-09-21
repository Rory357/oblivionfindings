<?php

namespace App\Console\Commands;

use App\Services\Fleet\MaintenanceEffectDispatcher;
use Illuminate\Console\Command;

class DispatchMaintenanceEffects extends Command
{
    protected $signature = 'maintenance:dispatch-effects {--limit=25 : Maximum due effects per run}';

    protected $description = 'Deliver due maintenance notifications with persisted retry state';

    public function handle(MaintenanceEffectDispatcher $dispatcher): int
    {
        $results = $dispatcher->dispatchDue((int) $this->option('limit'));
        $delivered = count(array_filter($results, static fn (string $state) => $state === 'delivered'));
        $retry = count(array_filter($results, static fn (string $state) => $state === 'retry'));
        $this->info("Maintenance effects: {$delivered} delivered, {$retry} pending retry.");

        return $retry ? self::FAILURE : self::SUCCESS;
    }
}
