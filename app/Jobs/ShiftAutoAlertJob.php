<?php

namespace App\Jobs;

use App\Models\Shift;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftSignalService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ShiftAutoAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 300;
    public $tries = 2;

    /**
     * Severity thresholds for no-show / late-start alerts.
     * PR7: Normalised — shift no-show is operationally urgent but not life-threatening.
     * Critical reserved for life-safety events only.
     */
    protected array $thresholdSeverities = [
        30 => 'medium',
        60 => 'high',
        90 => 'high',
    ];

    /**
     * Severity thresholds for not-completed alerts.
     * PR7: Normalised — extended not-completed is high, not critical.
     */
    protected array $completionThresholdSeverities = [
        30 => 'medium',
        60 => 'high',
        120 => 'high',
    ];

    public function handle(
        ShiftSignalService $signals,
        ShiftCoverageService $coverage,
        SignalProcessingService $processor,
    ): void
    {
        try {
            $now = now();

            $this->detectNoShows($signals, $now);
            $this->detectLateStarts($signals);
            $this->resolveStartAlerts($processor, $now);
            $this->detectNotCompleted($signals, $now);
            $this->resolveNotCompletedAlerts($processor);
            $activeCoverageWindows = $this->detectUncoveredShifts($signals, $coverage, $now);
            $this->resolveCoverageAlerts($processor, $activeCoverageWindows);
        } catch (\Throwable $exception) {
            Log::error('ShiftAutoAlertJob failed: '.$exception->getMessage(), [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    protected function detectNoShows(ShiftSignalService $signals, CarbonInterface $now): void
    {
        Shift::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'attendanceSessions:id,shift_id,clock_in_at,clock_out_at,status',
            ])
            ->where('status', 'scheduled')
            ->whereNotNull('user_id')
            ->where('starts_at', '<=', $now->copy()->subMinutes(15))
            ->each(function (Shift $shift) use ($signals, $now) {
                $actualStart = $this->resolveActualStart($shift);
                if ($actualStart) {
                    return;
                }

                $threshold = $this->highestTriggeredThreshold($shift->starts_at, $now, array_keys($this->thresholdSeverities));
                if (! $threshold) {
                    return;
                }

                $signals->emitForShift(
                    $shift,
                    ShiftSignalService::TYPE_NO_SHOW,
                    $this->thresholdSeverities[$threshold],
                    $shift->starts_at->copy()->addMinutes($threshold),
                    [
                        'reason' => 'No clock-in or actual start was recorded after the planned shift start.',
                        'threshold_minutes' => $threshold,
                        'minutes_late' => $shift->starts_at->diffInMinutes($now),
                        'planned_start' => $shift->starts_at?->toISOString(),
                        'planned_end' => $shift->ends_at?->toISOString(),
                    ],
                    'threshold:'.$threshold,
                );
            });
    }

    protected function detectLateStarts(ShiftSignalService $signals): void
    {
        Shift::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'attendanceSessions:id,shift_id,clock_in_at,clock_out_at,status',
            ])
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(function ($query) {
                $query->whereNotNull('actual_starts_at')
                    ->orWhereHas('attendanceSessions', fn ($attendance) => $attendance->whereNotNull('clock_in_at'));
            })
            ->each(function (Shift $shift) use ($signals) {
                $actualStart = $this->resolveActualStart($shift);
                if (! $shift->starts_at || ! $actualStart) {
                    return;
                }

                $minutesLate = $shift->starts_at->diffInMinutes($actualStart, false);
                if ($minutesLate < 15) {
                    return;
                }

                $threshold = $this->highestTriggeredThreshold($shift->starts_at, $actualStart, array_keys($this->thresholdSeverities));
                if (! $threshold) {
                    return;
                }

                $signals->emitForShift(
                    $shift,
                    ShiftSignalService::TYPE_LATE_START,
                    $this->thresholdSeverities[$threshold],
                    $actualStart,
                    [
                        'reason' => 'The shift started materially later than planned.',
                        'threshold_minutes' => $threshold,
                        'minutes_late' => $minutesLate,
                        'planned_start' => $shift->starts_at?->toISOString(),
                        'actual_start' => $actualStart->toISOString(),
                    ],
                    'threshold:'.$threshold.'|started:'.$actualStart->format('YmdHi'),
                );
            });
    }

    protected function detectNotCompleted(ShiftSignalService $signals, CarbonInterface $now): void
    {
        Shift::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'attendanceSessions:id,shift_id,clock_in_at,clock_out_at,status',
            ])
            ->where('status', 'in_progress')
            ->where('ends_at', '<=', $now->copy()->subMinutes(30))
            ->each(function (Shift $shift) use ($signals, $now) {
                $actualEnd = $this->resolveActualEnd($shift);
                if ($actualEnd) {
                    return;
                }

                $threshold = $this->highestTriggeredThreshold($shift->ends_at, $now, array_keys($this->completionThresholdSeverities));
                if (! $threshold) {
                    return;
                }

                $signals->emitForShift(
                    $shift,
                    ShiftSignalService::TYPE_NOT_COMPLETED,
                    $this->completionThresholdSeverities[$threshold],
                    $shift->ends_at->copy()->addMinutes($threshold),
                    [
                        'reason' => 'The shift has run past its planned end without completion evidence.',
                        'threshold_minutes' => $threshold,
                        'minutes_overdue' => $shift->ends_at->diffInMinutes($now),
                        'planned_start' => $shift->starts_at?->toISOString(),
                        'planned_end' => $shift->ends_at?->toISOString(),
                    ],
                    'threshold:'.$threshold,
                );
            });
    }

    protected function detectUncoveredShifts(
        ShiftSignalService $signals,
        ShiftCoverageService $coverage,
        CarbonInterface $now,
    ): array {
        $activeCoverageWindowKeys = [];
        $coverageWindows = collect($coverage->buildRangeCoverage(
            $now->copy(),
            $now->copy()->addMinutes(30),
        ));

        $coverageWindows
            ->filter(fn (array $window) => $this->isActionableCoverageGap($window))
            ->each(function (array $window) use ($signals, $now, &$activeCoverageWindowKeys) {
                $coverageWindowKey = $signals->buildCoverageWindowKey($window);
                $deficitSignature = $signals->buildCoverageDeficitSignature($window);
                $activeCoverageWindowKeys[] = $coverageWindowKey;

                $signals->emitCoverageGap(
                    $window,
                    $this->determineCoverageSeverity($window, $now),
                    $this->coverageOccurredAt($window, $now),
                    [
                        'reason' => 'This coverage window has an active staffing deficit and requires review.',
                        'coverage_window_key' => $coverageWindowKey,
                        'deficit_signature' => $deficitSignature,
                        'coverage_status' => $this->normalizeCoverageWindow($window, $coverageWindowKey),
                    ],
                );
            });

        Shift::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'site:id,name',
                'serviceContext:id,name,type',
            ])
            ->where('status', 'scheduled')
            ->whereNull('user_id')
            ->where('ends_at', '>', $now)
            ->where(function ($query) use ($now) {
                $query->where('starts_at', '<=', $now->copy()->addMinutes(30))
                    ->orWhereBetween('starts_at', [$now->copy()->subMinutes(60), $now]);
            })
            ->each(function (Shift $shift) use ($signals, $coverage, $now) {
                $coverageStatus = $coverage->coverageStatusForShift($shift);
                if (is_array($coverageStatus)) {
                    return;
                }

                $severity = 'medium';
                $windowKey = 'upcoming_30';
                $occurredAt = $now->copy();

                if ($shift->starts_at && $shift->starts_at->lte($now->copy()->subMinutes(60))) {
                    $severity = 'high'; // PR7: high, not critical — staffing gap, not life-safety
                    $windowKey = 'started_60';
                    $occurredAt = $shift->starts_at->copy()->addMinutes(60);
                } elseif ($shift->starts_at && $shift->starts_at->lte($now->copy()->subMinutes(15))) {
                    $severity = 'high';
                    $windowKey = 'started_15';
                    $occurredAt = $shift->starts_at->copy()->addMinutes(15);
                }

                $signals->emitForShift(
                    $shift,
                    ShiftSignalService::TYPE_UNCOVERED,
                    $severity,
                    $occurredAt,
                    [
                        'reason' => 'This unassigned shift has no configured coverage rule and requires staffing review.',
                        'window_key' => $windowKey,
                        'planned_start' => $shift->starts_at?->toISOString(),
                        'planned_end' => $shift->ends_at?->toISOString(),
                    ],
                    $windowKey,
                );
            });

        $this->resolveLegacyShiftUncoveredAlerts($coverage, $activeCoverageWindowKeys);

        return array_values(array_unique($activeCoverageWindowKeys));
    }

    protected function resolveStartAlerts(SignalProcessingService $processor, CarbonInterface $now): void
    {
        Shift::query()
            ->with(['attendanceSessions:id,shift_id,clock_in_at,clock_out_at,status'])
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($query) {
                $query->whereNotNull('actual_starts_at')
                    ->orWhereHas('attendanceSessions', fn ($attendance) => $attendance->whereNotNull('clock_in_at'));
            })
            ->each(function (Shift $shift) use ($processor, $now) {
                $actualStart = $this->resolveActualStart($shift);
                if (! $shift->starts_at || ! $actualStart) {
                    return;
                }

                $minutesLate = $shift->starts_at->diffInMinutes($actualStart, false);

                if ($minutesLate >= 15) {
                    if ($actualStart->copy()->addMinutes(15)->lte($now) || $this->resolveActualEnd($shift) || $shift->status === 'completed') {
                        $processor->resolveShiftAlertsByShift(
                            $shift->id,
                            ShiftSignalService::START_ANOMALY_TYPES,
                            'Late-start alert resolved because the shift is now clearly underway with attendance evidence.',
                            ShiftSignalService::RESOLUTION_SOURCE_ATTENDANCE,
                            [
                                'shift_id' => $shift->id,
                                'actual_start' => $actualStart->toISOString(),
                            ],
                        );
                    }

                    return;
                }

                $processor->resolveShiftAlertsByShift(
                    $shift->id,
                    ShiftSignalService::START_ANOMALY_TYPES,
                    'No-show risk resolved because attendance evidence shows the shift has started.',
                    ShiftSignalService::RESOLUTION_SOURCE_ATTENDANCE,
                    [
                        'shift_id' => $shift->id,
                        'actual_start' => $actualStart->toISOString(),
                    ],
                );
            });
    }

    protected function resolveNotCompletedAlerts(SignalProcessingService $processor): void
    {
        Shift::query()
            ->with(['attendanceSessions:id,shift_id,clock_in_at,clock_out_at,status'])
            ->where(function ($query) {
                $query->where('status', 'completed')
                    ->orWhereNotNull('actual_ends_at')
                    ->orWhereHas('attendanceSessions', fn ($attendance) => $attendance->whereNotNull('clock_out_at'));
            })
            ->each(function (Shift $shift) use ($processor) {
                $actualEnd = $this->resolveActualEnd($shift);
                if (! $actualEnd) {
                    return;
                }

                $processor->resolveShiftAlertsByShift(
                    $shift->id,
                    [ShiftSignalService::TYPE_NOT_COMPLETED],
                    'Not-completed alert resolved because shift completion evidence was recorded.',
                    ShiftSignalService::RESOLUTION_SOURCE_SHIFT_COMPLETION,
                    [
                        'shift_id' => $shift->id,
                        'actual_end' => $actualEnd->toISOString(),
                    ],
                );
            });
    }

    protected function resolveCoverageAlerts(SignalProcessingService $processor, array $activeCoverageWindowKeys): void
    {
        $openCoverageAlerts = \App\Models\ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'shift_operations')
            ->where('alert_type', $this->alertTypeFor(ShiftSignalService::TYPE_UNCOVERED))
            ->whereRaw("JSON_EXTRACT(context, '$.normalized_data.coverage_window_key') IS NOT NULL")
            ->get();

        foreach ($openCoverageAlerts as $alert) {
            $coverageWindowKey = data_get($alert->context, 'normalized_data.coverage_window_key');
            if (! $coverageWindowKey || in_array($coverageWindowKey, $activeCoverageWindowKeys, true)) {
                continue;
            }

            $processor->resolveShiftCoverageAlert(
                $coverageWindowKey,
                'Coverage-gap alert resolved because the current window no longer shows an actionable deficit.',
                ShiftSignalService::RESOLUTION_SOURCE_COVERAGE,
                [
                    'coverage_window_key' => $coverageWindowKey,
                ],
            );
        }
    }

    protected function resolveActualStart(Shift $shift): ?CarbonInterface
    {
        if ($shift->actual_starts_at) {
            return $shift->actual_starts_at;
        }

        $clockIn = $shift->attendanceSessions
            ->whereNotNull('clock_in_at')
            ->sortBy('clock_in_at')
            ->first();

        return $clockIn?->clock_in_at;
    }

    protected function resolveActualEnd(Shift $shift): ?CarbonInterface
    {
        if ($shift->actual_ends_at) {
            return $shift->actual_ends_at;
        }

        $clockOut = $shift->attendanceSessions
            ->whereNotNull('clock_out_at')
            ->sortByDesc('clock_out_at')
            ->first();

        return $clockOut?->clock_out_at;
    }

    /**
     * @param  array<int, int>  $thresholds
     */
    protected function highestTriggeredThreshold(
        ?CarbonInterface $baseline,
        CarbonInterface $reference,
        array $thresholds,
    ): ?int {
        if (! $baseline) {
            return null;
        }

        return collect($thresholds)
            ->sortDesc()
            ->first(fn (int $threshold) => $baseline->copy()->addMinutes($threshold)->lte($reference));
    }

    protected function isActionableCoverageGap(array $window): bool
    {
        if (! empty($window['has_actionable_gap'])) {
            return true;
        }

        if ((int) ($window['unfilled_after_open_shifts'] ?? 0) > 0) {
            return true;
        }

        return collect($window['role_shortages'] ?? [])
            ->contains(fn (array $shortage) => (int) ($shortage['missing'] ?? 0) > 0);
    }

    protected function determineCoverageSeverity(array $window, CarbonInterface $now): string
    {
        $windowStart = Carbon::parse($window['starts_at']);
        $deficit = max(
            (int) ($window['unfilled_after_open_shifts'] ?? 0),
            (int) ($window['missing_staff'] ?? 0)
        );

        if ($windowStart->lte($now)) {
            // PR7: high for coverage gaps — critical reserved for life-safety
            return $deficit >= 2 ? 'high' : 'high';
        }

        return $deficit >= 2 ? 'high' : 'medium';
    }

    protected function coverageOccurredAt(array $window, CarbonInterface $now): CarbonInterface
    {
        $windowStart = Carbon::parse($window['starts_at']);

        return $windowStart->lte($now)
            ? $now->copy()
            : $windowStart;
    }

    protected function normalizeCoverageWindow(array $window, string $coverageWindowKey): array
    {
        return [
            'site_id' => $window['site_id'] ?? null,
            'site_name' => $window['site_name'] ?? null,
            'rule_id' => $window['rule_id'] ?? null,
            'rule_name' => $window['rule_name'] ?? null,
            'coverage_type' => $window['coverage_type'] ?? null,
            'service_context_id' => $window['service_context_id'] ?? null,
            'service_context_name' => $window['service_context_name'] ?? null,
            'role_shortages' => $window['role_shortages'] ?? [],
            'required_staff' => (int) ($window['required_staff'] ?? 0),
            'assigned_staff' => (int) ($window['assigned_staff'] ?? 0),
            'planned_staff' => (int) ($window['planned_staff'] ?? 0),
            'missing_staff' => (int) ($window['missing_staff'] ?? 0),
            'deficit' => max(
                (int) ($window['unfilled_after_open_shifts'] ?? 0),
                (int) ($window['missing_staff'] ?? 0)
            ),
            'unfilled_after_open_shifts' => (int) ($window['unfilled_after_open_shifts'] ?? 0),
            'window_label' => $window['window_label'] ?? null,
            'starts_at' => $window['starts_at'] ?? null,
            'ends_at' => $window['ends_at'] ?? null,
            'open_shift_ids' => $window['open_shift_ids'] ?? [],
            'recommended_fill_action' => $window['recommended_fill_action'] ?? null,
            'coverage_window_key' => $coverageWindowKey,
        ];
    }

    protected function resolveLegacyShiftUncoveredAlerts(ShiftCoverageService $coverage, array $activeCoverageWindowKeys): void
    {
        Shift::query()
            ->with(['client:id,site_id'])
            ->where(function ($query) {
                $query->whereNotNull('user_id')
                    ->orWhereIn('status', ['cancelled', 'completed']);
            })
            ->each(function (Shift $shift) use ($coverage, $activeCoverageWindowKeys) {
                $coverageStatus = $coverage->coverageStatusForShift($shift);
                $coverageWindowKey = is_array($coverageStatus)
                    ? app(ShiftSignalService::class)->buildCoverageWindowKey($coverageStatus)
                    : null;

                if ($coverageWindowKey && in_array($coverageWindowKey, $activeCoverageWindowKeys, true)) {
                    return;
                }

                app(SignalProcessingService::class)->resolveShiftAlertsByShift(
                    $shift->id,
                    [ShiftSignalService::TYPE_UNCOVERED],
                    'Uncovered-shift alert resolved because staffing or coverage for the shift is now restored.',
                    ShiftSignalService::RESOLUTION_SOURCE_COVERAGE,
                    [
                        'shift_id' => $shift->id,
                    ],
                );
            });
    }

    protected function alertTypeFor(string $signalType): string
    {
        return app(ShiftSignalService::class)->alertTypeForSignalType($signalType);
    }
}
