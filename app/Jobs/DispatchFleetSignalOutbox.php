<?php

namespace App\Jobs;

use App\Models\FleetSignalOutbox;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchFleetSignalOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $outboxId)
    {
    }

    public function handle(): void
    {
        $outbox = FleetSignalOutbox::query()->find($this->outboxId);
        if (!$outbox || $outbox->status === 'sent') {
            return;
        }

        try {
            $signal = $outbox->signal()->first();
            if ($signal) {
                $processor = app(SignalProcessingService::class);
                $controlSignal = $processor->ingestFromFleetSignal($signal);
                $processor->process($controlSignal);
            }

            $outbox->update([
                'status' => 'sent',
                'attempts' => $outbox->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $outbox->update([
                'status' => 'failed',
                'attempts' => $outbox->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
    }
}
