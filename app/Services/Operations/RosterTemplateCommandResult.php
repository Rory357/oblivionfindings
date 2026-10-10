<?php

namespace App\Services\Operations;

/** Persisted aggregate evidence captured before the governing transaction ends. */
final readonly class RosterTemplateCommandResult
{
    public function __construct(
        public string $action,
        public int $actorId,
        public int $templateId,
        public ?int $copyId,
        public ?string $requestId,
        public ?array $expectedSource,
        public string $outcome,
        public bool $changed,
        public string $valuesHash,
        public ?string $resultRevision,
        public int $templateShiftsCount,
        public bool $canViewRoster,
    ) {}
}
