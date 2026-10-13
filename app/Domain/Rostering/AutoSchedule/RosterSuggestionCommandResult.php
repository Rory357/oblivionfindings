<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Models\RosterSuggestion;

/** Persisted scalar evidence captured before the transaction ends. */
final readonly class RosterSuggestionCommandResult
{
    public function __construct(
        public string $action,
        public int $actorId,
        public int $runId,
        public int $siteId,
        public ?int $suggestionId,
        public ?string $requestId,
        public array $expectedSource,
        public string $outcome,
        public bool $changed,
        public string $disposition,
        public array $counts,
        public ?array $suggestion,
        public array $assignments = [],
        /** Compatibility return value for the public planning service. */
        public ?RosterSuggestion $model = null,
    ) {}
}
