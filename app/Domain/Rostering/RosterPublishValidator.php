<?php

namespace App\Domain\Rostering;

use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Services\ShiftConflictService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Support\Collection;

class RosterPublishValidator
{
    public function __construct(
        private readonly RosterPeriodService $periods,
        private readonly ShiftStaffEligibilityService $eligibility,
        private readonly ShiftCoverageService $coverage,
        private readonly ShiftConflictService $conflicts,
    ) {
    }

    /**
     * @return array{can_publish: bool, blocks: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>, shift_count: int}
     */
    public function validate(RosterPeriod $period): array
    {
        $shifts = $this->periods
            ->shiftsQuery($period)
            ->with(['staff:id,name,email', 'client:id,first_name,last_name', 'site:id,name'])
            ->orderBy('starts_at')
            ->get();

        $blocks = [];
        $warnings = [];

        foreach ($shifts as $shift) {
            if (in_array($shift->status, ['completed', 'cancelled'], true)) {
                continue;
            }

            if (! $shift->user_id) {
                $warnings[] = $this->entry($shift, 'unassigned', 'This shift is still open.');
                continue;
            }

            if (! $shift->staff) {
                $blocks[] = $this->entry($shift, 'missing_staff_record', 'Assigned staff member could not be loaded.');
                continue;
            }

            $result = $this->eligibility->evaluate($shift, $shift->staff)->toArray();

            foreach ($result['blocked_reasons'] ?? [] as $reason) {
                $blocks[] = $this->entry($shift, 'eligibility_block', $reason);
            }

            foreach ($result['warning_reasons'] ?? [] as $reason) {
                $warnings[] = $this->entry($shift, 'eligibility_warning', $reason);
            }
        }

        foreach ($this->coverageWarnings($period) as $warning) {
            $warnings[] = $warning;
        }

        return [
            'can_publish' => $blocks === [],
            'blocks' => $blocks,
            'warnings' => $warnings,
            'shift_count' => $shifts->count(),
        ];
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array{can_publish: bool, blocks: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>, shift_count: int}
     */
    public function validateProposedShifts(Collection $shifts): array
    {
        $blocks = [];
        $warnings = [];

        foreach ($shifts->values() as $index => $shift) {
            if (! $shift->user_id) {
                $warnings[] = $this->proposedEntry($shift, 'unassigned', 'This template row will create an open shift.', $index);
                continue;
            }

            if (! $shift->staff) {
                $blocks[] = $this->proposedEntry($shift, 'missing_staff_record', 'Assigned staff member could not be loaded.', $index);
                continue;
            }

            $result = $this->eligibility->evaluate($shift, $shift->staff)->toArray();

            foreach ($result['blocked_reasons'] ?? [] as $reason) {
                $blocks[] = $this->proposedEntry($shift, 'eligibility_block', $reason, $index);
            }

            foreach ($result['warning_reasons'] ?? [] as $reason) {
                $warnings[] = $this->proposedEntry($shift, 'eligibility_warning', $reason, $index);
            }

            foreach ($this->conflicts->findClientOverlapWarnings($shift->client_id, $shift->starts_at, $shift->ends_at) as $overlap) {
                $warnings[] = $this->proposedEntry(
                    $shift,
                    'client_overlap',
                    $this->conflicts->overlapWarningsMessage(collect([$overlap]))
                        ?? 'This client already has another overlapping shift scheduled.',
                    $index,
                );
            }
        }

        $proposedShifts = $shifts->values();

        foreach ($proposedShifts as $leftIndex => $left) {
            foreach ($proposedShifts->slice($leftIndex + 1)->values() as $rightOffset => $right) {
                $rightIndex = $leftIndex + 1 + $rightOffset;

                if (! $this->overlaps($left, $right)) {
                    continue;
                }

                if ($left->user_id && (int) $left->user_id === (int) $right->user_id) {
                    $blocks[] = $this->proposedEntry(
                        $left,
                        'proposed_staff_conflict',
                        'This template row overlaps another proposed row for the same staff member (row '.($rightIndex + 1).').',
                        $leftIndex,
                    );
                }

                if ($left->client_id && (int) $left->client_id === (int) $right->client_id) {
                    $warnings[] = $this->proposedEntry(
                        $left,
                        'proposed_client_overlap',
                        'This template row overlaps another proposed row for the same client (row '.($rightIndex + 1).').',
                        $leftIndex,
                    );
                }
            }
        }

        return [
            'can_publish' => $blocks === [],
            'blocks' => $blocks,
            'warnings' => $warnings,
            'shift_count' => $shifts->count(),
        ];
    }

    private function entry(Shift $shift, string $type, string $message): array
    {
        return [
            'shift_id' => $shift->id,
            'issue_type' => $type,
            'message' => $message,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'client' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : null,
            'staff' => $shift->staff?->name,
            'site' => $shift->site?->name,
            'fix_url' => route('operations.shifts.show', $shift, false),
        ];
    }

    private function proposedEntry(Shift $shift, string $type, string $message, int $index): array
    {
        return [
            'shift_id' => null,
            'issue_type' => $type,
            'message' => $message,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'client' => $shift->client ? trim($shift->client->first_name.' '.$shift->client->last_name) : null,
            'staff' => $shift->staff?->name,
            'site' => $shift->site?->name,
            'template_row' => $index + 1,
            'fix_url' => null,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function coverageWarnings(RosterPeriod $period): Collection
    {
        $weekStart = $period->week_start->copy()->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(7);

        return collect($this->coverage->buildSiteSummaries($weekStart, $weekEnd, $period->site_id))
            ->flatMap(fn (array $site) => collect($site['alerts'] ?? [])->map(fn (array $alert) => [
                'shift_id' => null,
                'issue_type' => 'coverage_gap',
                'message' => sprintf(
                    '%s needs %d more staff.',
                    $alert['window_label'] ?? 'Coverage window',
                    (int) ($alert['missing_staff'] ?? 0),
                ),
                'site' => $site['site_name'] ?? null,
                'starts_at' => $alert['starts_at'] ?? null,
                'ends_at' => $alert['ends_at'] ?? null,
                'client' => null,
                'staff' => null,
                'fix_url' => route('operations.rostering.index', [
                    'week' => $period->week_start->toDateString(),
                    'site_id' => $period->site_id,
                ], false),
            ]))
            ->values();
    }

    private function overlaps(Shift $left, Shift $right): bool
    {
        if (! $left->starts_at || ! $left->ends_at || ! $right->starts_at || ! $right->ends_at) {
            return false;
        }

        return $left->starts_at->lt($right->ends_at)
            && $right->starts_at->lt($left->ends_at);
    }
}
