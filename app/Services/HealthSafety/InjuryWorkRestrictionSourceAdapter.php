<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\WorkRestrictionSourceAdapter;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;

/** Unbound H&S source bridge. HR alone owns restriction decisions and Workforce staging. */
final class InjuryWorkRestrictionSourceAdapter implements WorkRestrictionSourceAdapter
{
    public function __construct(private readonly InjuryAccessService $access) {}

    public function current(User $actor, array $expectations, CurrentAuthorizationReads $reads): array
    {
        return $this->access->workRestrictionSourceForCurrentReads($actor, $expectations, $reads);
    }

    public function assertRecorded(User $actor, array $source, CurrentAuthorizationReads $reads): void
    {
        $this->access->assertRecordedWorkRestrictionSource($actor, $source, $reads);
    }
}
