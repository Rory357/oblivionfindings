<?php

namespace App\Domain\SecurityDevices\NetworkChecks\Jobs;

use App\Domain\SecurityDevices\NetworkChecks\NetworkChecks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Obli's next turn in a network check (plan, or write the answer), off the web request. The
 * minute tick (security-devices:network-checks-tick) picks up anything this misses.
 */
final class AdvanceNetworkCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly int $sessionId) {}

    public function handle(NetworkChecks $checks): void
    {
        $checks->advance($this->sessionId);
    }
}
