<?php

namespace App\Exceptions;

use RuntimeException;

class MedicationSignalDeliveryException extends RuntimeException
{
    public function __construct(
        private readonly ?int $signalId,
        private readonly string $signalType,
        private readonly string $deliveryReason,
    ) {
        parent::__construct(
            "Required medication signal {$signalType} was not delivered: {$deliveryReason}.",
        );
    }

    public function signalId(): ?int
    {
        return $this->signalId;
    }

    public function signalType(): string
    {
        return $this->signalType;
    }

    public function reason(): string
    {
        return $this->deliveryReason;
    }
}
