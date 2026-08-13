<?php

namespace App\Jobs;

use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Exceptions\SafetySignalUnroutable;
use App\Observers\DeviceEventObserver;
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

class DispatchDeviceEventSignalOutbox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 300;

    public function __construct(public int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(DeviceEventObserver $observer): void
    {
        try {
            DB::transaction(function () use ($observer): void {
                $outbox = DeviceEventSignalOutbox::query()
                    ->whereKey($this->outboxId)
                    ->lockForUpdate()
                    ->first();

                if ($outbox === null || in_array($outbox->status, ['sent', 'dead_letter', 'unroutable'], true)) {
                    return;
                }

                $event = $outbox->event()->first();
                if ($event === null) {
                    throw new RuntimeException('Device event source row is unavailable.');
                }

                $outbox->forceFill([
                    'status' => 'processing',
                    'attempts' => (int) $outbox->attempts + 1,
                    'last_attempt_at' => now(),
                    'last_error' => null,
                ])->save();

                $observer->deliver($event);

                $outbox->forceFill([
                    'status' => 'sent',
                    'last_error' => null,
                ])->save();
            }, 3);
        } catch (SafetySignalUnroutable $exception) {
            $this->recordFailure('unroutable', $exception);

            Log::error('Device-event safety signal is unroutable', [
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
                $outbox = DeviceEventSignalOutbox::query()->whereKey($this->outboxId)->lockForUpdate()->first();
                if ($outbox === null || in_array($outbox->status, ['sent', 'unroutable'], true)) {
                    return;
                }

                $outbox->forceFill([
                    'status' => 'dead_letter',
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();

                Log::critical('Device-event safety signal permanently failed delivery', [
                    'outbox_id' => $this->outboxId,
                    'device_event_id' => $outbox->device_event_id,
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            });
        } catch (Throwable $failure) {
            Log::error('Failed to handle device-event safety signal dead-letter', [
                'outbox_id' => $this->outboxId,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    private function recordFailure(string $status, Throwable $exception): void
    {
        DB::transaction(function () use ($status, $exception): void {
            $outbox = DeviceEventSignalOutbox::query()->whereKey($this->outboxId)->lockForUpdate()->first();
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
