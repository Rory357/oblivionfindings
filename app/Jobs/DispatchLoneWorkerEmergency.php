<?php

namespace App\Jobs;

use App\Services\HealthSafety\LoneWorkerEmergencyDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchLoneWorkerEmergency implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $episodeId) {}

    public function handle(LoneWorkerEmergencyDeliveryService $delivery): void
    {
        $delivery->deliver($this->episodeId);
    }
}
