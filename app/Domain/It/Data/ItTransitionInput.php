<?php

namespace App\Domain\It\Data;

use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Enums\ItWorkflowState;
use App\Models\User;

final readonly class ItTransitionInput
{
    public function __construct(
        public User $actor,
        public ItWorkflowState $to,
        public ?string $reason = null,
        public ?string $waitingParty = null,
        public ?string $nextAction = null,
        public ?string $resolutionCode = null,
        public ?string $resolutionSummary = null,
        public string $source = 'manual',
        public ?int $expectedVersion = null,
        public ?string $resolutionVerification = null,
        public ?ItTicketCommandChannel $channel = null,
    ) {}

    public function withActor(User $actor): self
    {
        return new self(
            actor: $actor,
            to: $this->to,
            reason: $this->reason,
            waitingParty: $this->waitingParty,
            nextAction: $this->nextAction,
            resolutionCode: $this->resolutionCode,
            resolutionSummary: $this->resolutionSummary,
            source: $this->source,
            expectedVersion: $this->expectedVersion,
            resolutionVerification: $this->resolutionVerification,
            channel: $this->channel,
        );
    }

    public function withReason(string $reason): self
    {
        return new self(
            actor: $this->actor, to: $this->to, reason: $reason,
            waitingParty: $this->waitingParty, nextAction: $this->nextAction,
            resolutionCode: $this->resolutionCode, resolutionSummary: $this->resolutionSummary,
            source: $this->source, expectedVersion: $this->expectedVersion,
            resolutionVerification: $this->resolutionVerification,
            channel: $this->channel,
        );
    }
}
