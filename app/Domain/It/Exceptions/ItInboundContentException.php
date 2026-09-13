<?php

namespace App\Domain\It\Exceptions;

use DomainException;

final class ItInboundContentException extends DomainException
{
    public function __construct(public readonly string $reason = 'invalid_message_content')
    {
        parent::__construct('The email content requires review.');
    }
}
