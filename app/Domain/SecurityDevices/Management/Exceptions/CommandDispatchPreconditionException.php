<?php

namespace App\Domain\SecurityDevices\Management\Exceptions;

use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CommandDispatchPreconditionException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $safeMessage,
        public readonly CommandStatus $terminalStatus = CommandStatus::Blocked,
    ) {
        parent::__construct($safeMessage);
    }

    public function asValidationException(): ValidationException
    {
        return ValidationException::withMessages(['command' => $this->safeMessage]);
    }
}
