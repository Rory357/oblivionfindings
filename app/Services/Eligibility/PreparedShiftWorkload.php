<?php

namespace App\Services\Eligibility;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;
use LogicException;

/** One complete proposed worker workload, with exact candidate/source provenance. */
final class PreparedShiftWorkload
{
    private array $days = [];

    private array $weeks = [];

    private array $candidates = [];

    public function __construct(
        private readonly Collection $shifts,
        private readonly LocalWorkTimeSegmenter $segments,
        public readonly array $fatiguePolicy,
        public readonly int $userId,
        Collection $proposed,
        public readonly ?array $currentEvidence = null,
    ) {
        foreach ($proposed as $candidate) {
            $this->candidates[$this->key($candidate)] = $this->tuple($candidate);
        }
        foreach ($shifts as $shift) {
            if (! $shift->starts_at || ! $shift->ends_at) {
                continue;
            }
            foreach ($segments->byDay($shift->starts_at, $shift->ends_at) as $key => $hours) {
                $this->days[$key] = ($this->days[$key] ?? 0.0) + $hours;
            }
            foreach ($segments->byWeek($shift->starts_at, $shift->ends_at) as $key => $hours) {
                $this->weeks[$key] = ($this->weeks[$key] ?? 0.0) + $hours;
            }
        }
    }

    public function assertFor(Shift $candidate, User $user): void
    {
        if ((int) $user->id !== $this->userId
            || ($this->candidates[$this->key($candidate)] ?? null) !== $this->tuple($candidate)) {
            throw new LogicException('The prepared assignment workload no longer matches its candidate.');
        }
    }

    public function excluding(Shift $candidate): Collection
    {
        return $this->shifts->reject(fn (Shift $shift) => $this->sameOccurrence($shift, $candidate))->values();
    }

    public function conflictShifts(Shift $candidate): Collection
    {
        return $this->excluding($candidate)->whereNotIn('status', ['completed', 'cancelled'])->values();
    }

    public function daysExcluding(Shift $candidate): array
    {
        return $this->subtractCandidate($this->days, $candidate, 'byDay');
    }

    public function weeksExcluding(Shift $candidate): array
    {
        return $this->subtractCandidate($this->weeks, $candidate, 'byWeek');
    }

    private function subtractCandidate(array $totals, Shift $candidate, string $partition): array
    {
        if ($this->shifts->contains(fn (Shift $shift) => $this->sameOccurrence($shift, $candidate))) {
            foreach ($this->segments->{$partition}($candidate->starts_at, $candidate->ends_at) as $key => $hours) {
                $totals[$key] = max(0.0, ($totals[$key] ?? 0.0) - $hours);
            }
        }

        return array_filter($totals, fn (float $hours) => $hours > 0.000001);
    }

    private function sameOccurrence(Shift $left, Shift $right): bool
    {
        return $left === $right || ($left->id && $right->id && (int) $left->id === (int) $right->id);
    }

    private function key(Shift $shift): string
    {
        return $shift->id ? 'id:'.$shift->id : 'new:'.spl_object_id($shift);
    }

    private function tuple(Shift $shift): array
    {
        return [
            'id' => (int) $shift->id,
            'original_user_id' => $shift->exists ? (int) $shift->getRawOriginal('user_id') : $this->userId,
            'starts_at' => $shift->starts_at?->copy()->utc()->toJSON(),
            'ends_at' => $shift->ends_at?->copy()->utc()->toJSON(),
            'status' => $shift->status,
            'client_id' => (int) $shift->client_id,
            'site_id' => (int) $shift->site_id,
            'service_context_id' => $shift->service_context_id === null ? null : (int) $shift->service_context_id,
            'coverage_roles' => array_values($shift->coverage_roles ?? []),
            'required_licence_class' => $shift->required_licence_class,
            'required_licence_endorsements' => array_values($shift->required_licence_endorsements ?? []),
            'is_sleepover' => (bool) $shift->is_sleepover,
            'is_on_call' => (bool) $shift->is_on_call,
            'is_lone_worker' => (bool) $shift->is_lone_worker,
            'expected_break_minutes' => $shift->expected_break_minutes,
        ];
    }
}
