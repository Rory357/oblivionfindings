<?php

namespace App\Services\Facility;

use App\Enums\AlertSeverity;
use App\Jobs\DispatchFacilitySignalOutbox;
use App\Models\ControlRoomAlert;
use App\Models\FacilitySignal;
use App\Models\FacilitySignalOutbox;
use App\Models\ShiftTask;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Canonical facility signal emission service.
 *
 * Covers site/facility operational alerts:
 * - Inspection overdue / failed
 *
 * Flow: facility event → durable source/outbox → Control Room
 */
class FacilitySignalService
{
    public const TYPE_INSPECTION_OVERDUE = 'inspection_overdue';

    public const TYPE_INSPECTION_FAILED = 'inspection_failed';

    public const TYPE_SHIFT_TASK_DUE = 'shift_task_due';

    /**
     * Emit an inspection overdue signal.
     */
    public function emitInspectionOverdue(SiteInspectionSchedule $schedule, int $daysOverdue): void
    {
        $this->assertCanonicalSchedule($schedule);
        $schedule->loadMissing(['site:id,name', 'assignedTo:id,name']);

        // Only safety-critical overdue inspections (>7 days) go to CR as high.
        // 1-7 days overdue is medium.
        $severity = $daysOverdue > 7 ? AlertSeverity::HIGH : AlertSeverity::MEDIUM;

        $this->emit(
            self::TYPE_INSPECTION_OVERDUE,
            "Inspection overdue: {$schedule->title} at {$schedule->site?->name} ({$daysOverdue} days)",
            $severity,
            [
                'inspection_schedule_id' => $schedule->id,
                'inspection_type' => $schedule->inspection_type,
                'inspection_title' => $schedule->title,
                'site_id' => $schedule->site_id,
                'site_name' => $schedule->site?->name,
                'next_due_date' => $schedule->next_due_date?->toDateString(),
                'days_overdue' => $daysOverdue,
                'assigned_to_user_id' => $schedule->assigned_to_user_id,
                'assigned_to_name' => $schedule->assignedTo?->name,
                'frequency' => $schedule->frequency,
            ],
            $schedule->site_id,
        );
    }

    /**
     * Emit an inspection failed signal.
     */
    public function emitInspectionFailed(
        SiteInspectionSchedule $schedule,
        SiteInspectionRecord $record,
    ): void {
        $this->assertCanonicalSchedule($schedule);
        if (! $record->exists
            || $record->getKey() === null
            || $record->result !== 'fail'
            || (int) $record->schedule_id !== (int) $schedule->getKey()
            || (int) $record->site_id !== (int) $schedule->site_id
        ) {
            throw new InvalidArgumentException(
                'A failed Facility signal requires the exact persisted failed inspection record, schedule, and Site.',
            );
        }

        $schedule->loadMissing(['site:id,name', 'assignedTo:id,name']);

        $this->emit(
            self::TYPE_INSPECTION_FAILED,
            "Inspection FAILED: {$schedule->title} at {$schedule->site?->name}",
            AlertSeverity::HIGH,
            [
                'inspection_schedule_id' => $schedule->id,
                'inspection_record_id' => $record->id,
                'inspection_type' => $schedule->inspection_type,
                'inspection_title' => $schedule->title,
                'site_id' => $schedule->site_id,
                'site_name' => $schedule->site?->name,
                'result' => $record->result,
                'findings' => $record->findings,
                'corrective_actions' => $record->corrective_actions,
                'completed_at' => $record->completed_at?->toIso8601String(),
                'completed_by_user_id' => $record->completed_by_user_id,
                'due_date' => $record->due_date?->toDateString(),
                'assigned_to_user_id' => $schedule->assigned_to_user_id,
                'assigned_to_name' => $schedule->assignedTo?->name,
                'frequency' => $schedule->frequency,
            ],
            $schedule->site_id,
        );
    }

    public function emitShiftTaskDue(ShiftTask $task, User $worker): void
    {
        $task->loadMissing(['shift.client:id,first_name,last_name,site_id', 'shift.site:id,name']);
        $shift = $task->shift;

        $existing = ControlRoomAlert::query()
            ->where('source', 'shift_task')
            ->where('alert_type', 'Shift task due')
            ->where('context->shift_task_id', $task->id)
            ->unresolved()
            ->first();

        if ($existing) {
            return;
        }

        $scheduledFor = $task->scheduledFor();
        $clientName = $shift?->client
            ? trim($shift->client->first_name.' '.$shift->client->last_name)
            : null;
        $message = $clientName
            ? "{$task->label} is due now for {$clientName}."
            : "{$task->label} is due now.";

        ControlRoomAlert::query()->create([
            'source' => 'shift_task',
            'alert_type' => 'Shift task due',
            'severity' => AlertSeverity::LOW,
            'status' => ControlRoomAlert::STATUS_OPEN,
            'site_id' => $shift?->site_id ?? $shift?->client?->site_id,
            'client_id' => $shift?->client_id,
            'triggered_at' => now(),
            'due_at' => $scheduledFor,
            'assigned_to_user_id' => $worker->id,
            'assigned_at' => now(),
            'context' => [
                'summary' => $message,
                'message' => $message,
                'source_module' => 'my_day',
                'signal_type' => self::TYPE_SHIFT_TASK_DUE,
                'shift_id' => $task->shift_id,
                'shift_task_id' => $task->id,
                'task_label' => $task->label,
                'scheduled_time' => $task->scheduled_time,
                'scheduled_for' => $scheduledFor?->toIso8601String(),
                'worker_id' => $worker->id,
            ],
            'priority' => 'low',
            'category' => 'shift',
        ]);
    }

    /**
     * Core signal emission method.
     */
    protected function emit(
        string $signalType,
        string $message,
        string $severity,
        array $context,
        ?int $siteId = null,
    ): void {
        $occurredAt = now();
        $idempotencyKey = $this->buildIdempotencyKey($signalType, $context, $occurredAt);
        $normalizedData = array_merge([
            'title' => $message,
            'description' => $message,
            'source_module' => 'facility',
            'signal_type' => $signalType,
        ], $context);

        [$signal, $outboxId] = DB::transaction(function () use (
            $signalType,
            $severity,
            $context,
            $siteId,
            $idempotencyKey,
            $occurredAt,
            $normalizedData,
        ): array {
            try {
                $signal = FacilitySignal::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'site_id' => $siteId,
                        'inspection_schedule_id' => $context['inspection_schedule_id'] ?? null,
                        'inspection_record_id' => $context['inspection_record_id'] ?? null,
                        'signal_type' => $signalType,
                        'severity_hint' => $severity,
                        'occurred_at' => $occurredAt,
                        'payload' => $normalizedData,
                    ],
                );
            } catch (UniqueConstraintViolationException $exception) {
                // A locking read escapes MySQL's repeatable-read snapshot and
                // observes the winner of a concurrent first-write race.
                $signal = FacilitySignal::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($signal === null) {
                    throw $exception;
                }
            }

            $signal = FacilitySignal::query()
                ->whereKey($signal->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertImmutableSignalProvenance(
                $signal,
                $signalType,
                $severity,
                $context,
                $siteId,
            );
            try {
                $outbox = FacilitySignalOutbox::query()->firstOrCreate(
                    ['facility_signal_id' => $signal->id],
                    ['status' => 'pending'],
                );
            } catch (UniqueConstraintViolationException $exception) {
                $outbox = FacilitySignalOutbox::query()
                    ->where('facility_signal_id', $signal->id)
                    ->lockForUpdate()
                    ->first();
                if ($outbox === null) {
                    throw $exception;
                }
            }

            return [$signal, (int) $outbox->id];
        }, 3);

        $facilitySignalId = (int) $signal->id;
        $dispatch = function () use ($facilitySignalId, $outboxId): void {
            try {
                DispatchFacilitySignalOutbox::dispatch($outboxId);
            } catch (Throwable $exception) {
                // The source and outbox are already durable. The scheduled
                // recovery sweep will dispatch the pending intent.
                Log::error('Facility safety signal queue dispatch failed', [
                    'facility_signal_id' => $facilitySignalId,
                    'outbox_id' => $outboxId,
                    'error' => $exception->getMessage(),
                ]);
            }
        };

        DB::afterCommit($dispatch);
    }

    /** @param array<string, mixed> $context */
    private function assertImmutableSignalProvenance(
        FacilitySignal $signal,
        string $signalType,
        string $severity,
        array $context,
        ?int $siteId,
    ): void {
        $scheduleId = isset($context['inspection_schedule_id'])
            ? (int) $context['inspection_schedule_id']
            : null;
        $recordId = isset($context['inspection_record_id'])
            ? (int) $context['inspection_record_id']
            : null;

        if ($signal->signal_type !== $signalType
            || $signal->severity_hint !== AlertSeverity::normalise($severity)
            || ! $this->nullableIdsMatch($signal->site_id, $siteId)
            || ! $this->nullableIdsMatch($signal->inspection_schedule_id, $scheduleId)
            || ! $this->nullableIdsMatch($signal->inspection_record_id, $recordId)
        ) {
            throw new InvalidArgumentException(
                'A Facility signal idempotency key may only reuse the exact immutable signal type, Site, schedule, and record provenance.',
            );
        }
    }

    private function nullableIdsMatch(mixed $actual, ?int $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        return (int) $actual === $expected;
    }

    /**
     * Build idempotency key with appropriate dedup windows.
     */
    protected function buildIdempotencyKey(
        string $signalType,
        array $context,
        CarbonInterface $occurredAt,
    ): string {
        // A failed inspection is immutable record evidence. Its identity must
        // survive delayed replay and must never suppress another failed record
        // for the same schedule and calendar day.
        if ($signalType === self::TYPE_INSPECTION_FAILED) {
            return hash('sha256', implode('|', [
                'facility',
                $signalType,
                $context['inspection_record_id'] ?? 'unknown',
            ]));
        }

        // Overdue inspections dedup daily (one alert per schedule/day).
        $windowMinutes = str_starts_with($signalType, 'inspection') ? 1440 : 30;
        $window = $occurredAt->format('Y-m-d').($windowMinutes < 1440
            ? '_'.(intdiv((int) $occurredAt->format('G'), 1).':'.(intdiv((int) $occurredAt->format('i'), $windowMinutes) * $windowMinutes))
            : '');

        $entityKey = match ($signalType) {
            self::TYPE_INSPECTION_OVERDUE => $context['inspection_schedule_id'] ?? 'unknown',
            default => 'unknown',
        };

        return hash('sha256', implode('|', [
            'facility',
            $signalType,
            $entityKey,
            $window,
        ]));
    }

    private function assertCanonicalSchedule(SiteInspectionSchedule $schedule): void
    {
        if (! $schedule->exists
            || $schedule->getKey() === null
            || $schedule->site_id === null
            || (int) $schedule->site_id <= 0
        ) {
            throw new InvalidArgumentException(
                'A Facility inspection signal requires an exact persisted schedule and Site.',
            );
        }
    }
}
