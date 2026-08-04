<?php

namespace App\Domain\SecurityDevices\Management\Data;

use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;

final readonly class CommandDecisionInput
{
    public function __construct(
        public CommandApprovalDecision $decision,
        public string $comment,
    ) {}
}
