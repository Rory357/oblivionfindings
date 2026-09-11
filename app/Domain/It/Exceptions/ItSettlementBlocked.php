<?php

namespace App\Domain\It\Exceptions;

use DomainException;

/** A corrective target created only after the canonical private work check. */
final class ItSettlementBlocked extends DomainException
{
    public function __construct(
        string $message,
        public readonly int $ticketId,
        public readonly string $kind,
        public readonly ?int $recordId,
    ) {
        parent::__construct($message);
    }
}
