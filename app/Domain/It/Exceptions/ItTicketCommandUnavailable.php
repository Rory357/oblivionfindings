<?php

namespace App\Domain\It\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Used only after finding the current actor's own committed command receipt. */
final class ItTicketCommandUnavailable extends ModelNotFoundException
{
    public function __construct()
    {
        parent::__construct('The original request is no longer available to you. Its retained browser copy must be cleared.');
    }
}
