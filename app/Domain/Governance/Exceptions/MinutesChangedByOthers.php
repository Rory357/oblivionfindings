<?php

namespace App\Domain\Governance\Exceptions;

use DomainException;

/**
 * The minutes changed after the person acting on them loaded the page —
 * someone else saved, approved or corrected them in the meantime. Callers
 * answer JSON requests with 409 and ask the person to refresh.
 */
final class MinutesChangedByOthers extends DomainException
{
}
