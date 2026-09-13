<?php

namespace App\Domain\It\Services;

use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Support\It\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

/** Pure clock evaluation; callers own authorization, locking and persistence. */
final class ItSlaClockService
{
    public const float AT_RISK_FRACTION = 0.25;

    /** Mutates the caller's locked ticket; never saves or sends notifications. */
    public function synchronize(ItTicket $ticket, CarbonInterface $at): array
    {
        $verdict = $this->verdict($ticket, $at);
        foreach ($verdict['clocks'] as $name => $clock) {
            $attribute = $name.'_breached_at';
            if ($clock['breached_at'] !== null && $ticket->{$attribute} === null) {
                $ticket->{$attribute} = CarbonImmutable::parse($clock['breached_at'])->utc();
            }
        }
        $ticket->sla_state = $verdict['state'];
        $ticket->sla_checked_at = CarbonImmutable::instance($at)->utc();

        return $verdict;
    }

    /** @return array<string, mixed> */
    public function policySnapshot(string $priority, ?ItSlaPolicy $policy, CarbonInterface $at): array
    {
        [$response, $resolution] = $policy !== null
            ? [(int) $policy->first_response_minutes, (int) $policy->resolution_minutes]
            : (ItSlaPolicy::DEFAULTS[$priority] ?? ItSlaPolicy::DEFAULTS['normal']);
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');

        return [
            'version' => 1,
            'priority' => $priority,
            'source' => $policy !== null ? 'configured' : 'default',
            'policy_id' => $policy?->id,
            'first_response_minutes' => $response,
            'resolution_minutes' => $resolution,
            'calendar' => $policy !== null && ! empty($policy->business_hours) ? [
                'business_hours' => $policy->business_hours,
                'holiday_dates' => $policy->holiday_dates ?? [],
                'timezone' => $timezone,
            ] : null,
            'timezone' => $timezone,
            'pause_unit' => 'business_minutes',
            'pause_parties' => ['requester', 'vendor', 'approver', 'team', 'change', 'other'],
            'recorded_at' => CarbonImmutable::instance($at)->utc()->toIso8601String(),
        ];
    }

    /**
     * No policy lookup is performed: current configuration cannot silently
     * rewrite an existing ticket's original measurement. Old known deadlines
     * still prove late/met outcomes; unknown calendars cannot prove risk ratios.
     *
     * @return array{state: string, coverage: string, ever_breached: bool, evaluated_at: string, clocks: array<string, array<string, mixed>>}
     */
    public function verdict(ItTicket $ticket, CarbonInterface $at): array
    {
        $now = CarbonImmutable::instance($at);
        $policy = is_array($ticket->sla_policy_snapshot) ? $ticket->sla_policy_snapshot : null;
        $clocks = [
            'first_response' => $this->safeClock($ticket, 'first_response', $policy, $now),
            'resolution' => $this->safeClock($ticket, 'resolution', $policy, $now),
        ];
        $states = array_column($clocks, 'state');
        $measured = count(array_filter($states, fn (string $state) => $state !== 'unmeasured'));
        $everBreached = in_array(true, array_column($clocks, 'ever_breached'), true);
        $state = match (true) {
            $everBreached => 'breached',
            in_array('at_risk', $states, true) => 'at_risk',
            $measured < 2 => 'unmeasured',
            $states === ['met', 'met'] => 'met',
            in_array('paused', $states, true) => 'paused',
            default => 'ok',
        };

        return [
            'state' => $state,
            'coverage' => match ($measured) {
                0 => 'none', 2 => 'full', default => 'partial'
            },
            'ever_breached' => $everBreached,
            'evaluated_at' => $now->utc()->toIso8601String(),
            'clocks' => $clocks,
        ];
    }

    /**
     * Single-pass aggregation. The caller supplies only authorized records;
     * a lazy/cursor iterable keeps aggregation memory independent of row count.
     * A partial record is never silently added to the fully measured denominator.
     */
    public function summarize(iterable $tickets, CarbonInterface $at): array
    {
        $states = array_fill_keys(ItTicket::SLA_STATES, 0);
        $summary = [
            'total' => 0,
            'by_state' => $states,
            'by_coverage' => ['none' => 0, 'partial' => 0, 'full' => 0],
            'ever_breached' => 0,
            'clocks' => ['first_response' => ['by_state' => $states], 'resolution' => ['by_state' => $states]],
            'evaluated_at' => CarbonImmutable::instance($at)->utc()->toIso8601String(),
        ];
        foreach ($tickets as $ticket) {
            $verdict = $this->verdict($ticket, $at);
            $summary['total']++;
            $summary['by_state'][$verdict['state']]++;
            $summary['by_coverage'][$verdict['coverage']]++;
            $summary['ever_breached'] += (int) $verdict['ever_breached'];
            foreach ($verdict['clocks'] as $name => $clock) {
                $summary['clocks'][$name]['by_state'][$clock['state']]++;
            }
        }

        return $summary;
    }

    private function safeClock(ItTicket $ticket, string $name, ?array $policy, CarbonImmutable $now): array
    {
        try {
            return $this->clock($ticket, $name, $policy, $now);
        } catch (DomainException) {
            $breach = $this->instant($ticket->{$name.'_breached_at'});

            return [
                'state' => 'unmeasured', 'reason' => 'calendar_cannot_measure',
                'due_at' => null, 'completed_at' => null,
                'breached_at' => $breach?->utc()->toIso8601String(),
                'ever_breached' => $breach !== null, 'policy_recorded' => $policy !== null,
                'paused' => false, 'paused_minutes' => null, 'remaining_minutes' => null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>|null  $policy
     * @return array<string, mixed>
     */
    private function clock(ItTicket $ticket, string $name, ?array $policy, CarbonImmutable $now): array
    {
        $resolution = $name === 'resolution';
        $due = $this->instant($resolution ? $ticket->resolution_due_at : $ticket->first_response_due_at);
        $completed = $this->instant($resolution ? $ticket->resolved_at : $ticket->first_responded_at);
        $historicalBreach = $this->instant($resolution ? $ticket->resolution_breached_at : $ticket->first_response_breached_at);
        $created = $this->instant($ticket->created_at);
        $calendar = is_array($policy['calendar'] ?? null) ? $policy['calendar'] : null;
        $target = $policy[$name.'_minutes'] ?? null;
        $policyKnown = ($policy['version'] ?? null) === 1 && is_int($target) && $target > 0;
        $paused = $resolution && $ticket->waiting_since !== null && $completed === null;
        $base = [
            'state' => 'unmeasured',
            'reason' => null,
            'due_at' => $due?->utc()->toIso8601String(),
            'completed_at' => $completed?->utc()->toIso8601String(),
            'breached_at' => $historicalBreach?->utc()->toIso8601String(),
            'ever_breached' => $historicalBreach !== null,
            'policy_recorded' => $policyKnown,
            'paused' => $paused,
            'paused_minutes' => null,
            'remaining_minutes' => null,
        ];
        if ($due === null || $created === null) {
            return [...$base, 'reason' => 'clock_not_recorded'];
        }
        if ($due->lt($created) || ($completed !== null && $completed->lt($created))) {
            return [...$base, 'reason' => 'invalid_clock_order'];
        }
        if ($resolution && (int) $ticket->reopened_count > 0) {
            return [...$base, 'reason' => 'reopen_clock_policy_required'];
        }
        if ($resolution && ($policy['pause_unit'] ?? null) === 'legacy_unknown') {
            return [...$base, 'reason' => 'pause_calendar_not_recorded'];
        }

        $pauseMinutes = $resolution ? max(0, (int) $ticket->sla_paused_minutes) : 0;
        if ($resolution && ($pauseMinutes > 0 || $paused)) {
            if (! $policyKnown || ($policy['pause_unit'] ?? null) !== 'business_minutes') {
                return [...$base, 'reason' => 'pause_calendar_not_recorded'];
            }
            if ($paused) {
                $pauseMinutes += BusinessHours::workingMinutesBetween($this->instant($ticket->waiting_since), $now, $calendar);
            }
            $due = BusinessHours::addWorkingMinutes($due, $pauseMinutes, $calendar);
        }
        $base['due_at'] = $due->utc()->toIso8601String();
        $base['paused_minutes'] = $pauseMinutes;
        if ($completed === null && in_array($ticket->status, ['resolved', 'closed'], true)) {
            return [...$base, 'reason' => 'completion_not_recorded'];
        }

        $late = $completed !== null ? $completed->gt($due) : $now->gte($due);
        if ($late || $historicalBreach !== null) {
            $breachAt = $historicalBreach !== null && (! $late || $historicalBreach->lte($due)) ? $historicalBreach : $due;

            return [...$base, 'state' => 'breached', 'ever_breached' => true, 'breached_at' => $breachAt->utc()->toIso8601String()];
        }
        if ($completed !== null) {
            return [...$base, 'state' => 'met'];
        }
        if (! $policyKnown) {
            return [...$base, 'reason' => 'risk_calendar_not_recorded'];
        }

        $remaining = BusinessHours::workingMinutesBetween($now, $due, $calendar);

        return [
            ...$base,
            'state' => $paused ? 'paused' : ($remaining <= $target * self::AT_RISK_FRACTION ? 'at_risk' : 'ok'),
            'remaining_minutes' => $remaining,
        ];
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : ($value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value));
    }
}
