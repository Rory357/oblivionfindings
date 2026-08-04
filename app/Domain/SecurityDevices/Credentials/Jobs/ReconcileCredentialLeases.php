<?php

namespace App\Domain\SecurityDevices\Credentials\Jobs;

use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileCredentialLeases implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue('monitoring-maintenance');
    }

    public function handle(CredentialLeaseLifecycleService $leases): void
    {
        $leases->reconcile();
    }
}
