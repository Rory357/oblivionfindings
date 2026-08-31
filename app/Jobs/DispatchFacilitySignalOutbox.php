<?php

namespace App\Jobs;

use App\Exceptions\SafetySignalUnroutable;
use App\Models\ControlRoom\Signal;
use App\Models\FacilitySignal;
use App\Models\FacilitySignalOutbox;
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

class DispatchFacilitySignalOutbox implements ShouldBeUnique, ShouldQueue
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
        $unroutableError = null;

        try {
            DB::transaction(function () use ($processor, &$unroutableError): void {
                $outbox = FacilitySignalOutbox::query()
                    ->whereKey($this->outboxId)
                    ->lockForUpdate()
                    ->first();

                if ($outbox === null || in_array($outbox->status, ['sent', 'dead_letter', 'unroutable'], true)) {
                    return;
                }

                $outbox->forceFill([
                    'status' => 'processing',
                    'attempts' => (int) $outbox->attempts + 1,
                    'last_attempt_at' => now(),
                    'last_error' => null,
                ])->save();

                $signal = $outbox->signal()->first();
                if ($signal === null) {
                    throw new RuntimeException('Facility signal source row is unavailable.');
                }

                try {
                    $controlSignal = $processor->ingestFromFacilitySignal($signal);
                    if (! $controlSignal->site_id
                        || ! $controlSignal->signal_source_id
                        || $controlSignal->signalSource?->status !== 'active'
                    ) {
                        throw new SafetySignalUnroutable(
                            'Facility safety signal has no canonical Site or active signal source.',
                        );
                    }
                    $processedAlert = $processor->process($controlSignal);
                    $controlSignal->refresh();
                    $accepted = $controlSignal->status === 'suppressed'
                        || ($controlSignal->status === 'processed'
                            && ($processedAlert !== null
                                || $controlSignal->alert_id !== null
                                || $controlSignal->correlated_alert_id !== null
                                || $controlSignal->originAlert()->exists()));
                    if (! $accepted) {
                        throw new RuntimeException(
                            'Facility safety signal did not reach an accepted terminal processing state.',
                        );
                    }
                } catch (SafetySignalUnroutable $exception) {
                    $this->quarantineControlSignal($signal, $exception);
                    $unroutableError = mb_substr($exception->getMessage(), 0, 1000);
                    $outbox->forceFill([
                        'status' => 'unroutable',
                        'last_error' => $unroutableError,
                    ])->save();

                    return;
                }

                $outbox->forceFill([
                    'status' => 'sent',
                    'last_error' => null,
                ])->save();
            }, 3);
            if ($unroutableError !== null) {
                Log::error('Facility safety signal is unroutable', [
                    'outbox_id' => $this->outboxId,
                    'error' => mb_substr($unroutableError, 0, 500),
                ]);
            }
        } catch (Throwable $exception) {
            $this->recordFailure('failed', $exception);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($exception): void {
                $outbox = FacilitySignalOutbox::query()
                    ->whereKey($this->outboxId)
                    ->lockForUpdate()
                    ->first();
                if ($outbox === null || in_array($outbox->status, ['sent', 'unroutable'], true)) {
                    return;
                }

                $outbox->forceFill([
                    'status' => 'dead_letter',
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();
                $signal = $outbox->signal;

                Log::critical('Facility signal permanently failed delivery', [
                    'outbox_id' => $this->outboxId,
                    'signal_id' => $signal?->id,
                    'signal_type' => $signal?->signal_type,
                    'site_id' => $signal?->site_id,
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            });
        } catch (Throwable $e) {
            Log::error('Failed to handle Facility signal dead-letter: '.$e->getMessage());
        }
    }

    private function recordFailure(string $status, Throwable $exception): void
    {
        DB::transaction(function () use ($status, $exception): void {
            $outbox = FacilitySignalOutbox::query()
                ->whereKey($this->outboxId)
                ->lockForUpdate()
                ->first();
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

    private function quarantineControlSignal(
        FacilitySignal $facilitySignal,
        SafetySignalUnroutable $exception,
    ): void {
        $conflict = Signal::query()
            ->where('idempotency_key', hash(
                'sha256',
                'safety-signal|facility|'.$facilitySignal->idempotency_key,
            ))
            ->lockForUpdate()
            ->first();
        if ($conflict?->status === 'pending') {
            $conflict->markFailed(
                SignalProcessingService::FACILITY_QUARANTINE_PREFIX
                .mb_substr($exception->getMessage(), 0, 750),
            );
        }
    }
}
