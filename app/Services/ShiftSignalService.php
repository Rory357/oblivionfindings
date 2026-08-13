<?php

namespace App\Services;

use App\Jobs\DispatchShiftSignalOutbox;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\ShiftSignalOutbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShiftSignalService
{
    public const TYPE_NO_SHOW = 'shift_no_show';

    public const TYPE_LATE_START = 'shift_late_start';

    public const TYPE_NOT_COMPLETED = 'shift_not_completed';

    public const TYPE_UNCOVERED = 'shift_uncovered';

    public const START_ANOMALY_TYPES = [
        self::TYPE_NO_SHOW,
        self::TYPE_LATE_START,
    ];

    public const RESOLUTION_SOURCE_ATTENDANCE = 'attendance_evidence';

    public const RESOLUTION_SOURCE_SHIFT_COMPLETION = 'shift_completion';

    public const RESOLUTION_SOURCE_COVERAGE = 'coverage_restored';

    public function emit(array $payload): ShiftSignal
    {
        $idempotencyKey = $payload['idempotency_key'] ?? $this->buildIdempotencyKey($payload);

        [$signal, $outboxId] = DB::transaction(function () use ($payload, $idempotencyKey): array {
            $signal = ShiftSignal::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'shift_id' => $payload['shift_id'] ?? null,
                    'site_id' => $payload['site_id'] ?? null,
                    'client_id' => $payload['client_id'] ?? null,
                    'user_id' => $payload['user_id'] ?? null,
                    'signal_type' => $payload['signal_type'],
                    'severity_hint' => $payload['severity_hint'] ?? 'medium',
                    'occurred_at' => $payload['occurred_at'] ?? now(),
                    'payload' => $payload['payload'] ?? null,
                ]
            );

            $outbox = ShiftSignalOutbox::query()->firstOrCreate(
                ['shift_signal_id' => $signal->id],
                ['status' => 'pending'],
            );

            return [$signal, $outbox->id];
        }, 3);

        try {
            DispatchShiftSignalOutbox::dispatch($outboxId);
        } catch (Throwable $exception) {
            // The source and outbox are already durable. The scheduled recovery
            // sweep will re-dispatch this intent without duplicating the alert.
            Log::error('Shift safety signal queue dispatch failed', [
                'shift_signal_id' => $signal->id,
                'outbox_id' => $outboxId,
                'error' => $exception->getMessage(),
            ]);
        }

        return $signal;
    }

    public function emitForShift(
        Shift $shift,
        string $signalType,
        string $severity,
        CarbonInterface $occurredAt,
        array $payload = [],
        ?string $windowKey = null,
    ): ShiftSignal {
        $windowKey ??= (string) ($payload['window_key'] ?? $payload['threshold_minutes'] ?? 'default');

        return $this->emit([
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id ?: $shift->client?->site_id,
            'client_id' => $shift->client_id,
            'user_id' => $shift->user_id,
            'signal_type' => $signalType,
            'severity_hint' => $severity,
            'occurred_at' => $occurredAt,
            'idempotency_key' => $this->buildShiftIdempotencyKey($shift, $signalType, $windowKey),
            'payload' => $payload,
        ]);
    }

    public function buildShiftIdempotencyKey(Shift $shift, string $signalType, string $windowKey): string
    {
        return hash('sha256', implode('|', [
            'shift',
            $shift->id,
            $signalType,
            $windowKey,
        ]));
    }

    public function buildCoverageWindowKey(array $window): string
    {
        $start = $this->normalizeWindowPart($window['starts_at'] ?? null);
        $end = $this->normalizeWindowPart($window['ends_at'] ?? null);

        return implode(':', [
            'site',
            (string) ($window['site_id'] ?? 'unknown'),
            'rule',
            (string) ($window['rule_id'] ?? 'none'),
            'start',
            $start,
            'end',
            $end,
        ]);
    }

    public function buildCoverageDeficitSignature(array $window): string
    {
        $roleShortages = collect($window['role_shortages'] ?? [])
            ->map(fn (array $shortage) => [
                'key' => $shortage['key'] ?? null,
                'missing' => (int) ($shortage['missing'] ?? 0),
            ])
            ->sortBy('key')
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'required_staff' => (int) ($window['required_staff'] ?? 0),
            'assigned_staff' => (int) ($window['assigned_staff'] ?? 0),
            'planned_staff' => (int) ($window['planned_staff'] ?? 0),
            'missing_staff' => (int) ($window['missing_staff'] ?? 0),
            'unfilled_after_open_shifts' => (int) ($window['unfilled_after_open_shifts'] ?? 0),
            'gap_kind' => $window['gap_kind'] ?? null,
            'role_shortages' => $roleShortages,
        ]));
    }

    public function emitCoverageGap(
        array $window,
        string $severity,
        CarbonInterface $occurredAt,
        array $payload = [],
    ): ShiftSignal {
        $coverageWindowKey = $payload['coverage_window_key'] ?? $this->buildCoverageWindowKey($window);
        $deficitSignature = $payload['deficit_signature'] ?? $this->buildCoverageDeficitSignature($window);

        return $this->emit([
            'site_id' => $window['site_id'] ?? null,
            'signal_type' => self::TYPE_UNCOVERED,
            'severity_hint' => $severity,
            'occurred_at' => $occurredAt,
            'idempotency_key' => hash('sha256', implode('|', [
                'coverage-gap',
                $coverageWindowKey,
                $deficitSignature,
            ])),
            'payload' => array_merge([
                'coverage_window_key' => $coverageWindowKey,
                'deficit_signature' => $deficitSignature,
            ], $payload),
        ]);
    }

    public function alertTypeForSignalType(string $signalType): string
    {
        return match ($signalType) {
            self::TYPE_NO_SHOW => 'Shift No Show',
            self::TYPE_LATE_START => 'Shift Late Start',
            self::TYPE_NOT_COMPLETED => 'Shift Not Completed',
            self::TYPE_UNCOVERED => 'Shift Uncovered',
            default => str_replace('_', ' ', ucwords($signalType, '_')),
        };
    }

    protected function buildIdempotencyKey(array $payload): string
    {
        $occurredAt = $payload['occurred_at'] ?? now();
        if ($occurredAt instanceof CarbonInterface) {
            $occurredAt = $occurredAt->format('Y-m-d H:i');
        }

        return hash('sha256', implode('|', [
            'shift-signal',
            $payload['shift_id'] ?? '',
            $payload['site_id'] ?? '',
            $payload['client_id'] ?? '',
            $payload['user_id'] ?? '',
            $payload['signal_type'] ?? '',
            $occurredAt,
            json_encode($payload['payload'] ?? []),
        ]));
    }

    protected function normalizeWindowPart(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('YmdHi');
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->format('YmdHi');
        }

        return 'unknown';
    }
}
