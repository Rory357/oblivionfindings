<?php

namespace App\Domain\SecurityDevices\Management\Jobs;

use App\Domain\SecurityDevices\Management\Services\CollectorCommandRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecoverCollectorCommands implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue((string) config('security_devices.command_queue', 'monitoring-commands'));
    }

    public function handle(CollectorCommandRecoveryService $recovery): void
    {
        $recovery->recover();
    }
}
