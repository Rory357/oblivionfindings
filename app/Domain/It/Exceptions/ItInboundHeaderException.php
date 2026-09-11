<?php

namespace App\Domain\It\Exceptions;

use DomainException;

final class ItInboundHeaderException extends DomainException
{
    public function __construct(public readonly string $reason = 'invalid_message_headers')
    {
        parent::__construct('The email identification headers require review.');
    }
}
