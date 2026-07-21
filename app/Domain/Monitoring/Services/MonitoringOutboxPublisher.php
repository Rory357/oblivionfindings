<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Jobs\ConsumeMonitoringEnvelope;
use App\Domain\Monitoring\Jobs\PublishMonitoringOutbox;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class MonitoringOutboxPublisher
{
    public function __construct(
        private readonly RuntimeEnvelopeCodec $codec,
        private readonly MonitoringDeliveryRecoveryService $recovery,
    ) {}

    /**
     * @param  array<string|int, mixed>  $payload
     * @param  null|Closure(RuntimeEnvelope): void  $domainChange
     */
    public function stage(
        RuntimeMessageType $type,
        string $stream,
        string $source,
        string $idempotencyKey,
        array $payload,
        ?Closure $domainChange = null,
    ): MonitoringOutbox {
        $storeName = (string) config('monitoring.delivery.sequence_lock_store', 'redis');
        $driver = config("cache.stores.{$storeName}.driver");
        $localTestingLock = app()->environment('testing')
            && (bool) config('monitoring.delivery.allow_local_sequence_lock_for_tests', false);

        if ($driver !== 'redis' && ! $localTestingLock) {
            throw new RuntimeException('Monitoring sequence allocation requires a shared Redis lock store.');
        }

        $lock = Cache::store($storeName)->lock(
            'monitoring:source-sequence:'.hash('sha256', $source),
            (int) config('monitoring.delivery.sequence_lock_seconds', 15),
        );

        /** @var MonitoringOutbox $outbox */
        $outbox = $lock->block(
            (int) config('monitoring.delivery.sequence_lock_wait_seconds', 5),
            function () use ($type, $stream, $source, $idempotencyKey, $payload, $domainChange): MonitoringOutbox {
                return DB::transaction(function () use ($type, $stream, $source, $idempotencyKey, $payload, $domainChange): MonitoringOutbox {
                    $existing = MonitoringOutbox::query()
                        ->where('source', $source)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $existingEnvelope = $this->codec->decode($existing->envelope_bytes);
                        $this->assertEnvelopeMatchesOutbox($existing, $existingEnvelope);

                        if ($existing->stream !== $stream
                            || $existingEnvelope->type !== $type
                            || $existingEnvelope->source !== $source
                            || $existingEnvelope->idempotencyKey !== $idempotencyKey
                            || ! hash_equals(
                                $this->codec->canonicalPayloadBytes($existingEnvelope->payload),
                                $this->codec->canonicalPayloadBytes($payload),
                            )) {
                            throw new UnexpectedValueException('Monitoring idempotency key was reused with different content.');
                        }

                        $this->recovery->claimOutbox($existing);

                        return $existing;
                    }

                    $sequence = ((int) MonitoringOutbox::query()
                        ->where('source', $source)
                        ->lockForUpdate()
                        ->max('sequence')) + 1;
                    $envelope = RuntimeEnvelope::new($type, $source, $sequence, $idempotencyKey, $payload);
                    $domainChange?->__invoke($envelope);
                    $encoded = $this->codec->encode($envelope);
                    $outbox = MonitoringOutbox::create([
                        'message_id' => $envelope->messageId,
                        'stream' => $stream,
                        'source' => $source,
                        'sequence' => $sequence,
                        'idempotency_key' => $idempotencyKey,
                        'envelope_bytes' => $encoded,
                        'available_at' => now(),
                    ]);

                    $this->recovery->claimOutbox($outbox);

                    return $outbox;
                }, 3);
            },
        );

        $this->dispatchClaimedOutbox($outbox);

        return $outbox;
    }

    public function publish(int $outboxId, string $dispatchToken): void
    {
        $failure = null;

        DB::transaction(function () use ($outboxId, $dispatchToken, &$failure): void {
            $outbox = MonitoringOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null || $outbox->published_at !== null
                || ! is_string($outbox->dispatch_token)
                || ! hash_equals($outbox->dispatch_token, $dispatchToken)
                || $outbox->available_at->isFuture()) {
                return;
            }

            $outbox->forceFill(['attempts' => $outbox->attempts + 1])->save();

            try {
                $envelope = $this->codec->decode($outbox->envelope_bytes);
                $this->assertEnvelopeMatchesOutbox($outbox, $envelope);
                ConsumeMonitoringEnvelope::dispatch(
                    $this->consumerFor($envelope->type),
                    $outbox->envelope_bytes,
                )
                    ->onConnection((string) config('monitoring.delivery.queue_connection', 'redis'))
                    ->onQueue($this->queueFor($envelope->type));
            } catch (Throwable $exception) {
                $outbox->forceFill(['last_error' => 'Publish attempt failed.'])->save();
                $failure = $exception;

                return;
            }

            $outbox->forceFill([
                'published_at' => now(),
                'last_error' => null,
                'dispatch_token' => null,
                'dispatch_lease_until' => null,
            ])->save();
        }, 3);

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    private function dispatchClaimedOutbox(MonitoringOutbox $outbox): void
    {
        $token = $outbox->fresh()?->dispatch_token;

        if (! is_string($token) || $token === '') {
            return;
        }

        PublishMonitoringOutbox::dispatch($outbox->id, $token)
            ->afterCommit()
            ->onConnection((string) config('monitoring.delivery.queue_connection', 'redis'))
            ->onQueue((string) config('monitoring.queues.orchestration', 'monitoring'));
    }

    private function assertEnvelopeMatchesOutbox(MonitoringOutbox $outbox, RuntimeEnvelope $envelope): void
    {
        if ($envelope->messageId !== $outbox->message_id
            || $envelope->source !== $outbox->source
            || $envelope->sequence !== $outbox->sequence
            || $envelope->idempotencyKey !== $outbox->idempotency_key) {
            throw new UnexpectedValueException('Monitoring outbox envelope identity does not match its durable row.');
        }
    }

    private function consumerFor(RuntimeMessageType $type): string
    {
        return (string) config("monitoring.delivery.consumers.{$type->value}", "{$type->value}-projector");
    }

    private function queueFor(RuntimeMessageType $type): string
    {
        return (string) config("monitoring.delivery.type_queues.{$type->value}", config('monitoring.queues.orchestration', 'monitoring'));
    }
}
