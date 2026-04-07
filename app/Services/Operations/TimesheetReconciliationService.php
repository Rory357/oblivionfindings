<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Shift;
use App\Models\Timesheet;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TimesheetReconciliationService
{
    public const STATUS_CLEAR = 'clear';
    public const STATUS_REVIEW = 'review';
    public const STATUS_BLOCKED = 'blocked';

    public const SEVERITY_NONE = 'none';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';

    public const DURATION_REVIEW_TOLERANCE_MINUTES = 10;
    public const DURATION_BLOCK_TOLERANCE_MINUTES = 30;
    public const BOUNDS_TOLERANCE_MINUTES = 15;
    public const BOUNDS_HIGH_TOLERANCE_MINUTES = 60;

    /**
     * @return array{
     *   status:string,
     *   severity:string,
     *   detected_at:\Illuminate\Support\Carbon,
     *   summary:string,
     *   findings:array<int, array<string, mixed>>
     * }
     */
    public function reconcile(Timesheet $timesheet, ?HrAttendanceSession $preferredSession = null): array
    {
        $result = $this->evaluate($timesheet, $preferredSession);

        $timesheet->forceFill([
            'reconciliation_status' => $result['status'],
            'reconciliation_severity' => $result['severity'],
            'reconciliation_detected_at' => $result['detected_at'],
            'reconciliation_summary' => $result['summary'],
            'reconciliation_findings' => $result['findings'],
        ])->saveQuietly();

        return $result;
    }

    /**
     * @return array{
     *   status:string,
     *   severity:string,
     *   detected_at:\Illuminate\Support\Carbon,
     *   summary:string,
     *   findings:array<int, array<string, mixed>>
     * }
     */
    public function evaluate(Timesheet $timesheet, ?HrAttendanceSession $preferredSession = null): array
    {
        $timesheet->loadMissing([
            'shift:id,client_id,user_id,starts_at,ends_at,actual_starts_at,actual_ends_at,expected_break_minutes,status',
            'shift.attendanceSessions:id,user_id,shift_id,clock_in_at,clock_out_at,break_minutes,status',
            'attendanceSession:id,user_id,shift_id,clock_in_at,clock_out_at,break_minutes,status',
        ]);

        $detectedAt = now();
        $findings = [];
        $shift = $timesheet->shift;
        $attendanceSession = $this->resolveAttendanceSession($timesheet, $preferredSession, $findings);

        if ($shift && $shift->status === 'cancelled') {
            $findings[] = $this->finding(
                'shift_cancelled',
                self::SEVERITY_HIGH,
                'This timesheet is linked to a cancelled shift.',
                [
                    'shift_status' => $shift->status,
                ],
            );
        }

        if ($shift && $shift->user_id && (int) $shift->user_id !== (int) $timesheet->user_id) {
            $findings[] = $this->finding(
                'shift_user_mismatch',
                self::SEVERITY_HIGH,
                'The timesheet staff member does not match the assigned shift staff member.',
                [
                    'shift_user_id' => $shift->user_id,
                    'timesheet_user_id' => $timesheet->user_id,
                ],
            );
        }

        if ($shift && $shift->client_id && (int) $shift->client_id !== (int) $timesheet->client_id) {
            $findings[] = $this->finding(
                'shift_client_mismatch',
                self::SEVERITY_HIGH,
                'The timesheet client does not match the linked shift client.',
                [
                    'shift_client_id' => $shift->client_id,
                    'timesheet_client_id' => $timesheet->client_id,
                ],
            );
        }

        if ($attendanceSession) {
            if ((int) $attendanceSession->user_id !== (int) $timesheet->user_id) {
                $findings[] = $this->finding(
                    'attendance_user_mismatch',
                    self::SEVERITY_HIGH,
                    'The linked attendance session belongs to a different staff member.',
                    [
                        'attendance_user_id' => $attendanceSession->user_id,
                        'timesheet_user_id' => $timesheet->user_id,
                    ],
                );
            }

            if ($timesheet->shift_id && $attendanceSession->shift_id && (int) $attendanceSession->shift_id !== (int) $timesheet->shift_id) {
                $findings[] = $this->finding(
                    'attendance_shift_mismatch',
                    self::SEVERITY_HIGH,
                    'The linked attendance session is attached to a different shift.',
                    [
                        'attendance_shift_id' => $attendanceSession->shift_id,
                        'timesheet_shift_id' => $timesheet->shift_id,
                    ],
                );
            }

            if (! $attendanceSession->clock_out_at || $attendanceSession->status === 'open') {
                $findings[] = $this->finding(
                    'attendance_incomplete',
                    self::SEVERITY_HIGH,
                    'The linked attendance session is missing a clock-out and cannot support payroll safely.',
                    [
                        'attendance_session_id' => $attendanceSession->id,
                        'clock_in_at' => $attendanceSession->clock_in_at?->toISOString(),
                        'clock_out_at' => $attendanceSession->clock_out_at?->toISOString(),
                        'attendance_status' => $attendanceSession->status,
                    ],
                );
            }
        } elseif ($timesheet->shift_id) {
            $attendanceExpected = $shift
                && (
                    in_array($shift->status, ['in_progress', 'completed'], true)
                    || $shift->actual_starts_at
                    || $shift->actual_ends_at
                );

            if ($attendanceExpected) {
                $findings[] = $this->finding(
                    'attendance_missing',
                    self::SEVERITY_MEDIUM,
                    'This timesheet has no valid linked attendance evidence for the linked shift.',
                    [
                        'shift_id' => $timesheet->shift_id,
                        'shift_status' => $shift?->status,
                    ],
                );
            }
        }

        $plannedMinutes = $this->plannedShiftMinutes($shift);
        $attendanceMinutes = $this->attendanceMinutes($attendanceSession);
        $timesheetMinutes = (int) $timesheet->total_minutes;

        if ($plannedMinutes !== null && $attendanceMinutes !== null) {
            $difference = abs($plannedMinutes - $attendanceMinutes);
            if ($difference >= self::DURATION_REVIEW_TOLERANCE_MINUTES) {
                $findings[] = $this->finding(
                    'planned_vs_attendance_duration_mismatch',
                    $difference >= self::DURATION_BLOCK_TOLERANCE_MINUTES ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM,
                    'Attendance duration materially differs from the planned shift duration.',
                    [
                        'planned_duration_minutes' => $plannedMinutes,
                        'attendance_duration_minutes' => $attendanceMinutes,
                        'difference_minutes' => $difference,
                    ],
                );
            }
        }

        if ($attendanceMinutes !== null) {
            $difference = abs($attendanceMinutes - $timesheetMinutes);
            if ($difference >= self::DURATION_REVIEW_TOLERANCE_MINUTES) {
                $findings[] = $this->finding(
                    'attendance_vs_timesheet_duration_mismatch',
                    $difference >= self::DURATION_BLOCK_TOLERANCE_MINUTES ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM,
                    'Timesheet duration materially differs from attendance duration.',
                    [
                        'attendance_duration_minutes' => $attendanceMinutes,
                        'timesheet_duration_minutes' => $timesheetMinutes,
                        'difference_minutes' => $difference,
                    ],
                );
            }
        }

        $actualStart = $attendanceSession?->clock_in_at ?? $shift?->actual_starts_at;
        $actualEnd = $attendanceSession?->clock_out_at ?? $shift?->actual_ends_at;

        if ($shift?->starts_at && $actualStart) {
            $difference = abs($shift->starts_at->diffInMinutes($actualStart, false));
            if ($difference >= self::BOUNDS_TOLERANCE_MINUTES) {
                $findings[] = $this->finding(
                    'actual_start_outside_planned_bounds',
                    $difference >= self::BOUNDS_HIGH_TOLERANCE_MINUTES ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM,
                    'Actual start time sits materially outside the planned shift start.',
                    [
                        'planned_start' => $shift->starts_at->toISOString(),
                        'actual_start' => $actualStart->toISOString(),
                        'difference_minutes' => $difference,
                    ],
                );
            }
        }

        if ($shift?->ends_at && $actualEnd) {
            $difference = abs($shift->ends_at->diffInMinutes($actualEnd, false));
            if ($difference >= self::BOUNDS_TOLERANCE_MINUTES) {
                $findings[] = $this->finding(
                    'actual_end_outside_planned_bounds',
                    $difference >= self::BOUNDS_HIGH_TOLERANCE_MINUTES ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM,
                    'Actual end time sits materially outside the planned shift end.',
                    [
                        'planned_end' => $shift->ends_at->toISOString(),
                        'actual_end' => $actualEnd->toISOString(),
                        'difference_minutes' => $difference,
                    ],
                );
            }
        }

        $severity = $this->highestSeverity($findings);
        $status = match ($severity) {
            self::SEVERITY_HIGH => self::STATUS_BLOCKED,
            self::SEVERITY_MEDIUM => self::STATUS_REVIEW,
            default => self::STATUS_CLEAR,
        };

        return [
            'status' => $status,
            'severity' => $severity,
            'detected_at' => $detectedAt,
            'summary' => $this->summary($findings),
            'findings' => $findings,
        ];
    }

    public function assertWorkflowAllowed(Timesheet $timesheet, string $action, bool $persist = true): array
    {
        $result = $persist ? $this->reconcile($timesheet) : $this->evaluate($timesheet);

        if ($result['status'] !== self::STATUS_BLOCKED) {
            return $result;
        }

        throw ValidationException::withMessages([
            'timesheet' => sprintf(
                'This timesheet cannot be %s because reconciliation found blocking issues: %s',
                $action,
                $result['summary'],
            ),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, HrAttendanceSession>
     */
    public function attendanceWithoutTimesheets(): Collection
    {
        $sessions = HrAttendanceSession::query()
            ->with('timesheet:id,attendance_session_id')
            ->whereNotNull('clock_out_at')
            ->get()
            ->values();

        $existingPairs = $this->existingTimesheetPairs(
            $sessions
                ->filter(fn (HrAttendanceSession $session) => ! $session->timesheet && $session->shift_id)
                ->map(fn (HrAttendanceSession $session) => [
                    'shift_id' => (int) $session->shift_id,
                    'user_id' => (int) $session->user_id,
                ])
        );

        return $sessions
            ->filter(function (HrAttendanceSession $session) use ($existingPairs): bool {
                if ($session->timesheet) {
                    return false;
                }

                if (! $session->shift_id) {
                    return true;
                }

                return ! isset($existingPairs[$this->timesheetPairKey((int) $session->shift_id, (int) $session->user_id)]);
            })
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Shift>
     */
    public function completedShiftsWithoutTimesheets(): Collection
    {
        $shifts = Shift::query()
            ->where('status', 'completed')
            ->whereNotNull('user_id')
            ->where('status', '!=', 'cancelled')
            ->get()
            ->values();

        $existingPairs = $this->existingTimesheetPairs(
            $shifts->map(fn (Shift $shift) => [
                'shift_id' => (int) $shift->id,
                'user_id' => (int) $shift->user_id,
            ])
        );

        return $shifts
            ->filter(fn (Shift $shift) => ! isset($existingPairs[$this->timesheetPairKey((int) $shift->id, (int) $shift->user_id)]))
            ->values();
    }

    public function timesheetsNeedingReconciliationReview(): Collection
    {
        return Timesheet::query()
            ->reconciliationNeedsReview()
            ->orderByDesc('reconciliation_detected_at')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    protected function finding(string $type, string $severity, string $message, array $evidence = []): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  &$findings
     */
    protected function resolveAttendanceSession(Timesheet $timesheet, ?HrAttendanceSession $preferredSession, array &$findings): ?HrAttendanceSession
    {
        if ($preferredSession) {
            return $preferredSession;
        }

        if ($timesheet->attendanceSession) {
            return $timesheet->attendanceSession;
        }

        if (! $timesheet->shift_id) {
            return null;
        }

        $matchingSessions = HrAttendanceSession::query()
            ->where('shift_id', $timesheet->shift_id)
            ->where('user_id', $timesheet->user_id)
            ->orderBy('clock_in_at')
            ->get();

        if ($matchingSessions->count() === 1) {
            return $matchingSessions->first();
        }

        if ($matchingSessions->count() > 1) {
            $findings[] = $this->finding(
                'ambiguous_attendance_match',
                self::SEVERITY_HIGH,
                'Multiple attendance sessions match this shift and staff member, so reconciliation cannot safely assume which one supports payroll.',
                [
                    'attendance_session_ids' => $matchingSessions->pluck('id')->all(),
                    'shift_id' => $timesheet->shift_id,
                    'timesheet_user_id' => $timesheet->user_id,
                ],
            );
        }

        return null;
    }

    protected function plannedShiftMinutes(?Shift $shift): ?int
    {
        if (! $shift?->starts_at || ! $shift->ends_at) {
            return null;
        }

        return max(0, (int) $shift->starts_at->diffInMinutes($shift->ends_at) - (int) ($shift->expected_break_minutes ?? 0));
    }

    protected function attendanceMinutes(?HrAttendanceSession $session): ?int
    {
        if (! $session?->clock_in_at || ! $session->clock_out_at) {
            return null;
        }

        return max(0, (int) $session->clock_in_at->diffInMinutes($session->clock_out_at) - (int) ($session->break_minutes ?? 0));
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    protected function highestSeverity(array $findings): string
    {
        if (collect($findings)->contains(fn (array $finding) => ($finding['severity'] ?? null) === self::SEVERITY_HIGH)) {
            return self::SEVERITY_HIGH;
        }

        if ($findings !== []) {
            return self::SEVERITY_MEDIUM;
        }

        return self::SEVERITY_NONE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    protected function summary(array $findings): string
    {
        if ($findings === []) {
            return 'No reconciliation anomalies detected.';
        }

        return collect($findings)
            ->pluck('message')
            ->unique()
            ->take(3)
            ->implode(' ');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{shift_id:int, user_id:int}>  $pairs
     * @return array<string, true>
     */
    protected function existingTimesheetPairs(Collection $pairs): array
    {
        $normalizedPairs = $pairs
            ->filter(fn (array $pair) => ($pair['shift_id'] ?? 0) > 0 && ($pair['user_id'] ?? 0) > 0)
            ->map(fn (array $pair) => [
                'shift_id' => (int) $pair['shift_id'],
                'user_id' => (int) $pair['user_id'],
            ])
            ->unique(fn (array $pair) => $this->timesheetPairKey($pair['shift_id'], $pair['user_id']))
            ->values();

        if ($normalizedPairs->isEmpty()) {
            return [];
        }

        return Timesheet::query()
            ->where(function ($query) use ($normalizedPairs) {
                foreach ($normalizedPairs as $pair) {
                    $query->orWhere(function ($nested) use ($pair) {
                        $nested->where('shift_id', $pair['shift_id'])
                            ->where('user_id', $pair['user_id']);
                    });
                }
            })
            ->get(['shift_id', 'user_id'])
            ->mapWithKeys(fn (Timesheet $timesheet) => [
                $this->timesheetPairKey((int) $timesheet->shift_id, (int) $timesheet->user_id) => true,
            ])
            ->all();
    }

    protected function timesheetPairKey(int $shiftId, int $userId): string
    {
        return $shiftId.'|'.$userId;
    }
}
