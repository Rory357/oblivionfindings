<?php

namespace App\Domain\Shifts\Timesheets;

use Illuminate\Support\Collection;

final class BulkResult
{
    /**
     * @param  Collection<int, TimesheetWorkflowResult>  $results
     */
    public function __construct(
        public readonly Collection $results,
    ) {}

    /**
     * @param  iterable<int, TimesheetWorkflowResult>  $results
     */
    public static function fromResults(iterable $results): self
    {
        return new self(collect($results)->values());
    }

    public function changedCount(): int
    {
        return $this->results
            ->filter(fn (TimesheetWorkflowResult $result): bool => $result->changed)
            ->count();
    }

    /**
     * @return Collection<int, \App\Models\Timesheet>
     */
    public function timesheets(): Collection
    {
        return $this->results
            ->map(fn (TimesheetWorkflowResult $result) => $result->timesheet)
            ->values();
    }

    /**
     * @return Collection<int, \App\Models\Timesheet>
     */
    public function changedTimesheets(): Collection
    {
        return $this->results
            ->filter(fn (TimesheetWorkflowResult $result): bool => $result->changed)
            ->map(fn (TimesheetWorkflowResult $result) => $result->timesheet)
            ->values();
    }
}
