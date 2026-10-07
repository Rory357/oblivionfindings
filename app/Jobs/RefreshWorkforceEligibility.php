<?php

namespace App\Jobs;

use App\Services\Eligibility\WorkforceEligibilityRefresh;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshWorkforceEligibility implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $recheckId, public readonly int $sourceVersion) {}

    public function uniqueId(): string
    {
        return $this->recheckId.':'.$this->sourceVersion;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(WorkforceEligibilityRefresh $refresh): void
    {
        $refresh->process($this->recheckId, $this->sourceVersion);
    }
}
