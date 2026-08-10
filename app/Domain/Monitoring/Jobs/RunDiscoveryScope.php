<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

final class RunDiscoveryScope implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $runId)
    {
        if ($runId < 1) {
            throw new InvalidArgumentException('Discovery run identity is invalid.');
        }
        $this->onConnection('redis');
        $this->onQueue('monitoring-discovery');
    }

    public function handle(DiscoveryRunner $runner): void
    {
        $runner->execute($this->runId);
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }
}
