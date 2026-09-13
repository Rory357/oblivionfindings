<?php

namespace App\Jobs;

use App\Listeners\It\CreateOrUpdateMonitoringTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchDeviceMonitoringTicket implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $outboxId)
    {
        $this->onQueue('monitoring');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(CreateOrUpdateMonitoringTicket $listener): void
    {
        $listener->processOutbox($this->outboxId);
    }
}
