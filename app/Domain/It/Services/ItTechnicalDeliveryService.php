<?php

namespace App\Domain\It\Services;

use App\Services\AuditLogger;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Atomic IT outcome recording on an existing source outbox, independent of its acknowledgement. */
abstract class ItTechnicalDeliveryService
{
    abstract protected function outboxes(): Builder;

    abstract protected function auditPrefix(): string;

    abstract protected function canonicalEvent(Model $outbox): mixed;

    /** @param Closure(mixed): array{outcome: string, ticket_ids: list<int>} $apply */
    public function deliver(int $outboxId, Closure $apply): void
    {
        try {
            DB::transaction(function () use ($outboxId, $apply): void {
                $outbox = $this->outboxes()->whereKey($outboxId)->lockForUpdate()->firstOrFail();
                if ($outbox->status !== 'sent' || ! in_array($outbox->it_status, ['pending', 'failed'], true)) {
                    return;
                }
                if ($outbox->it_attempts >= $outbox->it_attempt_limit) {
                    $outbox->update(['it_status' => 'dead_letter']);

                    return;
                }
                $outbox->update(['it_attempts' => $outbox->it_attempts + 1, 'it_last_attempt_at' => now()]);
                $event = $this->canonicalEvent($outbox);
                $result = $apply($event);
                if (! in_array($result['outcome'], ['ticket_created', 'ticket_updated', 'recovery_recorded', 'recovery_unmatched'], true)) {
                    throw new DomainException('unsupported_outcome');
                }
                $outbox->update([
                    'it_status' => $result['outcome'] === 'recovery_unmatched' ? 'ignored' : 'applied',
                    'it_outcome_code' => $result['outcome'],
                    'it_ticket_ids' => $result['ticket_ids'],
                    'it_completed_at' => now(),
                ]);
                AuditLogger::logOrFail($this->auditPrefix().'.delivery_completed', $outbox, [
                    'fields' => ['status', 'state', 'it_attempts', 'completed_at'],
                    'after' => ['status' => $outbox->it_status, 'state' => $result['outcome'],
                        'it_attempts' => $outbox->it_attempts, 'completed_at' => $outbox->it_completed_at],
                ], systemActor: true);
            }, 3);
        } catch (Throwable $exception) {
            $unroutable = $exception instanceof DomainException;
            $code = $unroutable && in_array($exception->getMessage(), [
                'source_scope_changed', 'source_unavailable', 'technical_routing_unavailable',
            ], true) ? $exception->getMessage() : ($unroutable ? 'canonical_evidence_unavailable' : 'processing_failed');
            DB::transaction(function () use ($outboxId, $unroutable, $code): void {
                $outbox = $this->outboxes()->whereKey($outboxId)->lockForUpdate()->first();
                if (! $outbox || ! in_array($outbox->it_status, ['pending', 'failed'], true)) {
                    return;
                }
                $attempts = $outbox->it_attempts + 1;
                $outbox->update([
                    'it_attempts' => $attempts,
                    'it_last_attempt_at' => now(),
                    'it_status' => $unroutable ? 'unroutable' : ($attempts >= $outbox->it_attempt_limit ? 'dead_letter' : 'failed'),
                    'it_outcome_code' => $code,
                ]);
            }, 3);
            // Never persist exception messages or SQL/provider payloads in a cross-module outcome.
            Log::warning('Monitoring IT delivery did not complete', ['outbox_id' => $outboxId, 'code' => $code, 'exception_type' => $exception::class]);
            if (! $unroutable) {
                throw $exception;
            }
        }
    }

    /** Existing scheduler sweep calls this after recovering Control Room deliveries. */
    /** @return array{queued: int, pending: int, failures: int, legacy_unverified: int, failure_rows: list<array<string, mixed>>} */
    public function recover(int $limit, bool $reportOnly = false): array
    {
        $queued = 0;
        if (! $reportOnly) {
            $this->outboxes()->where('it_status', 'failed')->whereColumn('it_attempts', '>=', 'it_attempt_limit')
                ->update(['it_status' => 'dead_letter']);
            $rows = $this->outboxes()->where('status', 'sent')->whereIn('it_status', ['pending', 'failed'])
                ->whereColumn('it_attempts', '<', 'it_attempt_limit')
                ->where(fn ($query) => $query->whereNull('it_last_attempt_at')->orWhere('it_last_attempt_at', '<=', now()->subMinute()))
                ->orderBy('id')->limit(max(1, min($limit, 1000)))->get(['id']);
            foreach ($rows as $row) {
                try {
                    $this->dispatch((int) $row->id);
                    $queued++;
                } catch (Throwable $exception) {
                    Log::warning('Monitoring IT recovery dispatch failed', ['outbox_id' => $row->id, 'exception_type' => $exception::class]);
                }
            }
        }

        return [
            'queued' => $queued,
            'pending' => $this->outboxes()->where('it_status', 'pending')->count(),
            'failures' => $this->outboxes()->whereIn('it_status', ['failed', 'dead_letter', 'unroutable'])->count(),
            'legacy_unverified' => $this->outboxes()->where('status', 'sent')->whereNull('it_status')->count(),
            'failure_rows' => $this->outboxes()->whereIn('it_status', ['failed', 'dead_letter', 'unroutable'])
                ->orderBy('id')->limit(max(1, min($limit, 1000)))->get()->map(fn (Model $row): array => [
                    'source' => $this->deliverySource(), 'id' => $row->id, 'status' => $row->it_status,
                    'attempts' => $row->it_attempts, 'last_attempt_at' => $row->it_last_attempt_at?->toIso8601String(),
                    'last_error' => $row->it_outcome_code,
                ])->all(),
        ];
    }

    public function retry(int $outboxId): void
    {
        DB::transaction(function () use ($outboxId): void {
            $outbox = $this->outboxes()->whereKey($outboxId)->lockForUpdate()->firstOrFail();
            if ($outbox->status !== 'sent' || ! in_array($outbox->it_status, ['failed', 'dead_letter', 'unroutable'], true)) {
                throw new DomainException('Only failed IT outcomes of delivered source events can be retried.');
            }
            // Keep lifetime attempt evidence. A manual retry grants one bounded attempt.
            AuditLogger::logOrFail($this->auditPrefix().'.delivery_retry_requested', $outbox, [
                'fields' => ['status', 'state', 'it_attempts', 'it_attempt_limit'],
                'before' => ['status' => $outbox->it_status, 'state' => $outbox->it_outcome_code,
                    'it_attempts' => $outbox->it_attempts, 'it_attempt_limit' => $outbox->it_attempt_limit],
                'after' => ['status' => 'pending', 'state' => 'retry_requested',
                    'it_attempts' => $outbox->it_attempts, 'it_attempt_limit' => $outbox->it_attempts + 1],
            ]);
            $outbox->update(['it_status' => 'pending', 'it_attempt_limit' => $outbox->it_attempts + 1]);
        }, 3);
        try {
            $this->dispatch($outboxId);
        } catch (Throwable $exception) {
            // Pending intent and the explicit retry allowance survive a queue outage.
            Log::warning('Monitoring IT retry dispatch failed', ['outbox_id' => $outboxId, 'exception_type' => $exception::class]);
        }
    }

    abstract protected function deliverySource(): string;

    abstract protected function dispatch(int $outboxId): void;
}
