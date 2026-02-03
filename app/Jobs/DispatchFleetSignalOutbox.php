<?php

namespace App\Jobs;

use App\Models\FleetSignalOutbox;
use App\Models\ControlRoomAlert;
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
                ControlRoomAlert::create([
                    'source' => 'fleet',
                    'alert_type' => $signal->signal_type,
                    'severity' => $signal->severity_hint,
                    'status' => 'open',
                    'asset_id' => $signal->asset_id,
                    'fleet_signal_id' => $signal->id,
                    'triggered_at' => $signal->occurred_at ?? now(),
                    'context' => $signal->payload,
                ]);
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
