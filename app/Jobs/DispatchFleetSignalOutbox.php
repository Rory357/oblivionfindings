<?php

namespace App\Jobs;

use App\Exceptions\SafetySignalUnroutable;
use App\Models\FleetSignalOutbox;
use App\Services\ControlRoom\SignalProcessingService;
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

class DispatchFleetSignalOutbox implements ShouldBeUnique, ShouldQueue
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

    public function handle(SignalProcessingService $processor): void
    {
        try {
            DB::transaction(function () use ($processor): void {
                $outbox = FleetSignalOutbox::query()
                    ->whereKey($this->outboxId)
                    ->lockForUpdate()
                    ->first();

                if ($outbox === null || in_array($outbox->status, ['sent', 'dead_letter', 'unroutable'], true)) {
                    return;
                }

                $signal = $outbox->signal()->first();
                if ($signal === null) {
                    throw new RuntimeException('Fleet signal source row is unavailable.');
                }

                $outbox->forceFill([
                    'status' => 'processing',
                    'attempts' => (int) $outbox->attempts + 1,
                    'last_attempt_at' => now(),
                    'last_error' => null,
                ])->save();

                $controlSignal = $processor->ingestFromFleetSignal($signal);
                if (! $controlSignal->site_id || ! $controlSignal->signal_source_id) {
                    throw new SafetySignalUnroutable(
                        'Fleet safety signal has no canonical Site or active signal source.',
                    );
                }
                $processor->process($controlSignal);

                $outbox->forceFill([
                    'status' => 'sent',
                    'last_error' => null,
                ])->save();
            }, 3);
        } catch (SafetySignalUnroutable $exception) {
            $this->recordFailure('unroutable', $exception);

            Log::error('Fleet safety signal is unroutable', [
                'outbox_id' => $this->outboxId,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        } catch (Throwable $exception) {
            $this->recordFailure('failed', $exception);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($exception): void {
                $outbox = FleetSignalOutbox::query()->whereKey($this->outboxId)->lockForUpdate()->first();
                if ($outbox === null || in_array($outbox->status, ['sent', 'unroutable'], true)) {
                    return;
                }

                $outbox->forceFill([
                    'status' => 'dead_letter',
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();

                $signal = $outbox->signal;
                Log::critical('Fleet signal permanently failed delivery', [
                    'outbox_id' => $this->outboxId,
                    'signal_id' => $signal?->id,
                    'signal_type' => $signal?->signal_type,
                    'asset_id' => $signal?->asset_id,
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            });
        } catch (Throwable $e) {
            Log::error('Failed to handle fleet signal dead-letter: '.$e->getMessage());
        }
    }

    private function recordFailure(string $status, Throwable $exception): void
    {
        DB::transaction(function () use ($status, $exception): void {
            $outbox = FleetSignalOutbox::query()->whereKey($this->outboxId)->lockForUpdate()->first();
            if ($outbox === null || $outbox->status === 'sent') {
                return;
            }

            $outbox->forceFill([
                'status' => $status,
                'attempts' => (int) $outbox->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();
        });
    }
}
