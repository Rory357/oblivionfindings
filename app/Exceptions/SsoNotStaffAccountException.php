<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Staff Google/Microsoft sign-in returned an account that is not a staff
 * account: an email outside the organisation's domain, or a family portal
 * account. The staff sign-in page explains this and points to the family
 * portal, instead of showing a bare 403.
 */
class SsoNotStaffAccountException extends RuntimeException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct('This email is not a staff account.');
    }
}
