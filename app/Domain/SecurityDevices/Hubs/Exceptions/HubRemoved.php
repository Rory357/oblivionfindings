<?php

namespace App\Domain\SecurityDevices\Hubs\Exceptions;

use DomainException;

/**
 * The hub proved who it is, but it has been removed. It is told so (and only after its
 * signature checked out), so it wipes the saved logins it held and stops.
 */
final class HubRemoved extends DomainException
{
    public function __construct()
    {
        parent::__construct('This hub has been removed.');
    }
}
