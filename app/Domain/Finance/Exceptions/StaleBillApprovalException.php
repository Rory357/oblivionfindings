<?php

namespace App\Domain\Finance\Exceptions;

use InvalidArgumentException;

final class StaleBillApprovalException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('This bill changed after you reviewed it. Load the current bill and review its amount and allocations before approving.');
    }
}
