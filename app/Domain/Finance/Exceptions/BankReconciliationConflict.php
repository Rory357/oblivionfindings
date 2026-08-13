<?php

namespace App\Domain\Finance\Exceptions;

use DomainException;

class BankReconciliationConflict extends DomainException
{
    public static function generic(): self
    {
        return new self('The reconciliation operation could not be completed. Reload the workspace and try again.');
    }

    public static function terminal(): self
    {
        return new self('This reconciliation is completed and cannot be changed. Create an evidence-backed amendment to make a correction.');
    }

    public static function stale(): self
    {
        return new self('This reconciliation changed while you were working. Reload the workspace before trying again.');
    }
}
