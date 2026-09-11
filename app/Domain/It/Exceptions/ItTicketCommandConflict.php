<?php

namespace App\Domain\It\Exceptions;

use DomainException;

final class ItTicketCommandConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('This request identity was already used with different details. Check the original request before starting another.');
    }
}
