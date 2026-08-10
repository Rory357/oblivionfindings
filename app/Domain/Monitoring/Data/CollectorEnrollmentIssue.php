<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Models\CollectorEnrollment;

final readonly class CollectorEnrollmentIssue
{
    public function __construct(
        public CollectorEnrollment $enrollment,
        public string $plainToken,
    ) {}
}
