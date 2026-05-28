<?php

namespace App\Services\Eligibility\Rules;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Shift;
use App\Models\StaffAvailability;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Checks two conditions:
 *
 * 1. Staff declared availability (StaffAvailability) covers the shift window.
 * 2. No approved HR leave request overlaps the shift window.
 *
 * Returns up to two results (one per sub-check) via evaluateAll().
 * The single evaluate() method returns the first failing result or a pass.
 */
class AvailabilityRule implements EligibilityRuleInterface
{
    /**
     * @return array{rule: string, passed: bool, severity: 'block'|'warning'|'info', overrideable: bool, message: ?string}
     */
    public function evaluate(Shift $shift, User $user): array
    {
        $results = $this->evaluateAll($shift, $user);

        // Return first failure, or the first pass if everything is fine.
        foreach ($results as $result) {
            if (! $result['passed']) {
                return $result;
            }
        }

        return $results[0] ?? self::pass('availability');
    }

    /**
     * Return all sub-check results so the eligibility service can include each one
     * individually in checked_rules.
     *
     * @return array<int, array{rule: string, passed: bool, severity: string, overrideable: bool, message: ?string}>
     */
    public function evaluateAll(Shift $shift, User $user): array
    {
        return [
            $this->checkDeclaredAvailability($shift, $user),
            $this->checkApprovedLeave($shift, $user),
        ];
    }

    /**
     * Check StaffAvailability records against each calendar day the shift spans.
     */
    protected function checkDeclaredAvailability(Shift $shift, User $user): array
    {
        $startsAt = $this->resolveCarbon($shift->starts_at);
        $endsAt = $this->resolveCarbon($shift->ends_at);

        if (! $startsAt || ! $endsAt) {
            return self::pass('availability');
        }

        $availabilities = $user->relationLoaded('staffAvailability')
            ? $user->staffAvailability
            : StaffAvailability::where('user_id', $user->id)->get();

        if ($availabilities->isEmpty()) {
            // No availability records at all — soft warning, not a block.
            return [
                'rule' => 'availability',
                'passed' => false,
                'severity' => 'warning',
                'overrideable' => true,
                'message' => 'No availability records set for this staff member.',
            ];
        }

        // Determine each calendar day the shift touches.
        $segments = $this->splitIntoDaySegments($startsAt, $endsAt);

        foreach ($segments as $segment) {
            $dayOfWeek = $segment['day_of_week']; // 0=Sunday … 6=Saturday
            $segmentStart = $segment['starts_at']; // H:i:s string
            $segmentEnd = $segment['ends_at'];     // H:i:s string
            $dayName = $segment['day_name'];

            $daySlots = $availabilities->where('day_of_week', $dayOfWeek);

            if ($daySlots->isEmpty()) {
                return [
                    'rule' => 'availability',
                    'passed' => false,
                    'severity' => 'warning',
                    'overrideable' => true,
                    'message' => "No availability set for {$dayName}.",
                ];
            }

            // Check if any slot covers the segment.
            $covered = $daySlots->contains(function (StaffAvailability $slot) use ($segmentStart, $segmentEnd) {
                return $slot->starts_at <= $segmentStart && $slot->ends_at >= $segmentEnd;
            });

            if (! $covered) {
                $startFormatted = Carbon::createFromFormat('H:i:s', $segmentStart)->format('g:i A');
                $endFormatted = Carbon::createFromFormat('H:i:s', $segmentEnd)->format('g:i A');

                return [
                    'rule' => 'availability',
                    'passed' => false,
                    'severity' => 'warning',
                    'overrideable' => true,
                    'message' => "Staff not available on {$dayName} ({$startFormatted} – {$endFormatted}).",
                ];
            }
        }

        return self::pass('availability');
    }

    /**
     * Check whether any approved HrLeaveRequest overlaps the shift window.
     */
    protected function checkApprovedLeave(Shift $shift, User $user): array
    {
        $startsAt = $this->resolveCarbon($shift->starts_at);
        $endsAt = $this->resolveCarbon($shift->ends_at);

        if (! $startsAt || ! $endsAt) {
            return self::pass('availability_leave');
        }

        $leave = $user->relationLoaded('hrLeaveRequests')
            ? $user->hrLeaveRequests
                ->filter(fn (HrLeaveRequest $request) => $request->status === 'approved'
                    && $request->starts_at < $endsAt
                    && $request->ends_at > $startsAt)
                ->sortBy('starts_at')
                ->first()
            : HrLeaveRequest::query()
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->orderBy('starts_at')
                ->first();

        if (! $leave) {
            return self::pass('availability_leave');
        }

        $leaveType = ucfirst(str_replace('_', ' ', $leave->leave_type ?? 'leave'));
        $from = Carbon::parse($leave->starts_at)->format('j M');
        $to = Carbon::parse($leave->ends_at)->format('j M');

        return [
            'rule' => 'availability_leave',
            'passed' => false,
            'severity' => 'block',
            'overrideable' => false,
            'message' => "Approved {$leaveType} ({$from} – {$to}) overlaps this shift.",
        ];
    }

    /**
     * Split a shift window into per-day segments with day-of-week and time ranges.
     *
     * Handles overnight shifts by producing one segment per calendar day.
     *
     * @return array<int, array{day_of_week: int, starts_at: string, ends_at: string, day_name: string}>
     */
    protected function splitIntoDaySegments(CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $segments = [];
        $cursor = $startsAt->copy();

        while ($cursor->lt($endsAt)) {
            $dayEnd = $cursor->copy()->endOfDay();
            $segmentEnd = $dayEnd->lt($endsAt) ? $dayEnd : $endsAt;

            $segments[] = [
                'day_of_week' => (int) $cursor->dayOfWeek, // 0=Sunday, 6=Saturday
                'starts_at' => $cursor->format('H:i:s'),
                'ends_at' => $segmentEnd->eq($dayEnd) ? '23:59:59' : $segmentEnd->format('H:i:s'),
                'day_name' => $cursor->format('l'), // Monday, Tuesday, etc.
            ];

            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return $segments;
    }

    protected function resolveCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    /**
     * @return array{rule: string, passed: true, severity: 'block', overrideable: false, message: null}
     */
    protected static function pass(string $rule): array
    {
        return [
            'rule' => $rule,
            'passed' => true,
            'severity' => 'block',
            'overrideable' => false,
            'message' => null,
        ];
    }
}
