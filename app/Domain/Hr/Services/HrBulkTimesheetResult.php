<?php

namespace App\Domain\Hr\Services;

use Illuminate\Support\Collection;

final class HrBulkTimesheetResult
{
    /**
     * @param  Collection<int, HrTimesheetWorkflowResult>  $results
     */
    public function __construct(
        public readonly Collection $results,
    ) {}

    /**
     * @param  iterable<int, HrTimesheetWorkflowResult>  $results
     */
    public static function fromResults(iterable $results): self
    {
        return new self(collect($results)->values());
    }

    public function changedCount(): int
    {
        return $this->results
            ->filter(fn (HrTimesheetWorkflowResult $result): bool => $result->changed)
            ->count();
    }
}
