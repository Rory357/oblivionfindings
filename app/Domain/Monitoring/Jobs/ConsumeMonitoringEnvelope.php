<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ConsumeMonitoringEnvelope implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly string $consumer,
        public readonly string $envelopeBytes,
        public readonly ?int $trustedSiteId = null,
    ) {}

    public function handle(MonitoringEnvelopeConsumer $consumer): void
    {
        $consumer->consume($this->consumer, $this->envelopeBytes, $this->trustedSiteId);
    }

    public function failed(?Throwable $exception): void
    {
        app(MonitoringEnvelopeConsumer::class)->parkHandlerFailure(
            $this->consumer,
            $this->envelopeBytes,
            $this->trustedSiteId,
        );
    }
}
