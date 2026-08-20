<?php

namespace App\Jobs;

use App\Models\IncidentLifecycleSignalOutbox;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchIncidentLifecycleSignalOutbox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 30;

    public $backoff = [10, 30, 60];

    public $uniqueFor = 300;

    public function __construct(public int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(ControlRoomAlertLifecycleService $lifecycle): void
    {
        try {
            DB::transaction(function () use ($lifecycle): void {
                $outbox = IncidentLifecycleSignalOutbox::query()
                    ->whereKey($this->outboxId)
                    ->lockForUpdate()
                    ->first();
                if ($outbox === null || in_array($outbox->status, [
                    'sent',
                    'superseded',
                    'dead_letter',
                ], true)) {
                    return;
                }

                $signal = $outbox->signal()->first();
                if ($signal === null) {
                    throw new RuntimeException('Incident lifecycle signal source row is unavailable.');
                }

                $outbox->forceFill([
                    'status' => 'processing',
                    'attempts' => (int) $outbox->attempts + 1,
                    'last_attempt_at' => now(),
                    'last_error' => null,
                ])->save();

                $result = $lifecycle->applyIncidentLifecycleSignal($signal);
                $outbox->forceFill([
                    'status' => $result['status'],
                    'resulting_alert_id' => $result['alert_id'],
                    'delivered_at' => now(),
                    'last_error' => null,
                ])->save();
            }, 3);
        } catch (Throwable $exception) {
            $this->recordFailure($exception);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($exception): void {
                $outbox = IncidentLifecycleSignalOutbox::query()
                    ->whereKey($this->outboxId)
                    ->lockForUpdate()
                    ->first();
                if ($outbox === null || in_array($outbox->status, ['sent', 'superseded'], true)) {
                    return;
                }

                $outbox->forceFill([
                    'status' => 'dead_letter',
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();
                $signal = $outbox->signal;

                Log::critical('Incident lifecycle signal permanently failed delivery', [
                    'outbox_id' => $this->outboxId,
                    'signal_id' => $signal?->id,
                    'signal_type' => $signal?->signal_type,
                    'incident_id' => $signal?->client_incident_id,
                    'site_id' => $signal?->site_id,
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            });
        } catch (Throwable $handlingException) {
            Log::error('Failed to handle incident lifecycle signal dead-letter', [
                'outbox_id' => $this->outboxId,
                'error' => $handlingException->getMessage(),
            ]);
        }
    }

    private function recordFailure(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $outbox = IncidentLifecycleSignalOutbox::query()
                ->whereKey($this->outboxId)
                ->lockForUpdate()
                ->first();
            if ($outbox === null || in_array($outbox->status, ['sent', 'superseded'], true)) {
                return;
            }

            $outbox->forceFill([
                'status' => 'failed',
                'attempts' => (int) $outbox->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();
        });
    }
}
