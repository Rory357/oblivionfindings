<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Jobs\PublishMonitoringOutbox;
use App\Domain\Monitoring\Jobs\ReplayMonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MonitoringDeliveryRecoveryService
{
    /** @return array{outbox: int, replay: int} */
    public function recover(): array
    {
        $outboxClaims = $this->claimOutboxBatch();
        $replayClaims = $this->claimReplayBatch();

        foreach ($outboxClaims as $claim) {
            try {
                PublishMonitoringOutbox::dispatch($claim['id'], $claim['token'])
                    ->onConnection((string) config('monitoring.delivery.queue_connection', 'redis'))
                    ->onQueue((string) config('monitoring.queues.orchestration', 'monitoring'));
            } catch (Throwable) {
                // The durable lease expires so a later scheduled pass can retry.
            }
        }

        foreach ($replayClaims as $claim) {
            try {
                ReplayMonitoringDeadLetter::dispatch($claim['id'], $claim['token'])
                    ->onConnection((string) config('monitoring.delivery.queue_connection', 'redis'))
                    ->onQueue((string) config('monitoring.queues.orchestration', 'monitoring'));
            } catch (Throwable) {
                // The durable lease expires so a later scheduled pass can retry.
            }
        }

        return ['outbox' => count($outboxClaims), 'replay' => count($replayClaims)];
    }

    public function claimOutbox(MonitoringOutbox $outbox, bool $replace = false): ?string
    {
        if ($outbox->published_at !== null) {
            return null;
        }

        if (! $replace && is_string($outbox->dispatch_token)
            && $outbox->dispatch_token !== ''
            && $outbox->dispatch_lease_until?->isFuture()) {
            return $outbox->dispatch_token;
        }

        $token = (string) Str::uuid();
        $outbox->forceFill([
            'dispatch_token' => $token,
            'dispatch_lease_until' => now()->addSeconds($this->leaseSeconds()),
        ])->save();

        return $token;
    }

    public function claimReplay(MonitoringDeadLetter $letter, bool $replace = false): ?string
    {
        if ($letter->resolved_at !== null || $letter->replay_requested_at === null) {
            return null;
        }

        if (! $replace && is_string($letter->replay_intent_token)
            && $letter->replay_intent_token !== ''
            && $letter->replay_dispatch_lease_until?->isFuture()) {
            return $letter->replay_intent_token;
        }

        $token = (string) Str::uuid();
        $letter->forceFill([
            'replay_intent_token' => $token,
            'replay_dispatch_lease_until' => now()->addSeconds($this->leaseSeconds()),
        ])->save();

        return $token;
    }

    /** @return list<array{id: int, token: string}> */
    private function claimOutboxBatch(): array
    {
        return DB::transaction(function (): array {
            $query = MonitoringOutbox::query()
                ->whereNull('published_at')
                ->where('available_at', '<=', now())
                ->where(function (Builder $query): void {
                    $query->whereNull('dispatch_lease_until')
                        ->orWhere('dispatch_lease_until', '<=', now());
                })
                ->orderBy('id')
                ->limit($this->batchSize());
            $this->lockForRecovery($query);

            return $query->get()->map(function (MonitoringOutbox $outbox): array {
                return ['id' => (int) $outbox->id, 'token' => (string) $this->claimOutbox($outbox, true)];
            })->all();
        }, 3);
    }

    /** @return list<array{id: int, token: string}> */
    private function claimReplayBatch(): array
    {
        return DB::transaction(function (): array {
            $query = MonitoringDeadLetter::query()
                ->whereNull('resolved_at')
                ->whereNotNull('replay_requested_at')
                ->whereNotNull('replay_requested_by_user_id')
                ->whereNotNull('replay_request_reason')
                ->where(function (Builder $query): void {
                    $query->whereNull('replay_dispatch_lease_until')
                        ->orWhere('replay_dispatch_lease_until', '<=', now());
                })
                ->orderBy('id')
                ->limit($this->batchSize());
            $this->lockForRecovery($query);

            return $query->get()->map(function (MonitoringDeadLetter $letter): array {
                return ['id' => (int) $letter->id, 'token' => (string) $this->claimReplay($letter, true)];
            })->all();
        }, 3);
    }

    private function lockForRecovery(Builder $query): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $query->lockForUpdate();

            return;
        }

        $query->lock('for update skip locked');
    }

    private function batchSize(): int
    {
        return max(1, min(500, (int) config('monitoring.delivery.recovery_batch_size', 100)));
    }

    private function leaseSeconds(): int
    {
        return max(30, min(3600, (int) config('monitoring.delivery.dispatch_lease_seconds', 120)));
    }
}
