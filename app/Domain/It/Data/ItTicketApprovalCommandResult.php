<?php

namespace App\Domain\It\Data;

/** Original opaque outcome, separate from the current approval review. */
final readonly class ItTicketApprovalCommandResult
{
    public function __construct(public string $status, public array $data) {}

    public function toArray(): array
    {
        return ['status' => $this->status, 'data' => $this->data];
    }
}
