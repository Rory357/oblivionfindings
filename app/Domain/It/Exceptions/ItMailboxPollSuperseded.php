<?php

namespace App\Domain\It\Exceptions;

use RuntimeException;

final class ItMailboxPollSuperseded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This mailbox poll no longer owns the current connection.');
    }
}
