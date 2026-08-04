<?php

namespace App\Jobs;

use App\Services\Queclink\GovernedCommandLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpireQueclinkGovernedCommands implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue((string) config('security_devices.command_queue', 'monitoring-commands'));
    }

    public function handle(GovernedCommandLifecycleService $commands): void
    {
        $commands->recoverReconciliations();
        $commands->expireStale();
    }
}
