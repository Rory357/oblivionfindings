<?php

namespace App\Jobs;

use App\Models\ShiftSignalOutbox;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchShiftSignalOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [10, 30, 60];

    public function __construct(public int $outboxId)
    {
    }

    public function handle(): void
    {
        $outbox = ShiftSignalOutbox::query()->find($this->outboxId);
        if (! $outbox || $outbox->status === 'sent') {
            return;
        }

        try {
            $signal = $outbox->signal()->first();
            if ($signal) {
                $processor = app(SignalProcessingService::class);
                $controlSignal = $processor->ingestFromShiftSignal($signal);
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

    public function failed(\Throwable $exception): void
    {
        try {
            $outbox = ShiftSignalOutbox::query()->find($this->outboxId);
            if (! $outbox) {
                return;
            }

            $outbox->update(['status' => 'dead_letter']);
            $signal = $outbox->signal;

            Log::critical('Shift signal permanently failed delivery', [
                'outbox_id' => $this->outboxId,
                'signal_id' => $signal?->id,
                'signal_type' => $signal?->signal_type,
                'shift_id' => $signal?->shift_id,
                'site_id' => $signal?->site_id,
                'error' => substr($exception->getMessage(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to handle shift signal dead-letter: '.$e->getMessage());
        }
    }
}
