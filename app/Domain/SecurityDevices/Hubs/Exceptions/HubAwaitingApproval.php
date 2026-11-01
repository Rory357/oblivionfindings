<?php

namespace App\Domain\SecurityDevices\Hubs\Exceptions;

use DomainException;

/** The hub has connected but no admin has approved it yet, so it gets no work and no saved logins. */
final class HubAwaitingApproval extends DomainException
{
    public function __construct()
    {
        parent::__construct('This hub is waiting for an admin to approve it.');
    }
}
