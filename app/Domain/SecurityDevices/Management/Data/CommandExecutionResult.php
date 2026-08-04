<?php

namespace App\Domain\SecurityDevices\Management\Data;

use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;

final readonly class CommandExecutionResult
{
    /** @param array<string, scalar|null> $safeSummary */
    public function __construct(
        public CommandAttemptStatus $status,
        public array $safeSummary = [],
        public ?string $providerRequestReference = null,
        public ?string $evidenceReference = null,
        public ?string $safeFailureReason = null,
    ) {
        if (! in_array($status, [
            CommandAttemptStatus::Accepted,
            CommandAttemptStatus::Running,
            CommandAttemptStatus::Succeeded,
            CommandAttemptStatus::Failed,
            CommandAttemptStatus::Uncertain,
        ], true)) {
            throw new \InvalidArgumentException('A provider adapter must return an accepted, running, or final execution result.');
        }
    }
}
