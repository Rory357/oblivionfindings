<?php

namespace App\Domain\Hr\Exceptions;

use RuntimeException;

class AttendanceClockOutBlockedException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $blockers
     */
    public function __construct(private readonly array $blockers)
    {
        parent::__construct('End-of-shift checklist has outstanding items.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
