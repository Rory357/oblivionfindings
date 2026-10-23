<?php

namespace App\Domain\Hr\Services;

use App\Models\User;
use App\Services\CurrentAuthorizationReads;

/** H&S-owned implementation required; deliberately no container binding/default adapter. */
interface WorkRestrictionSourceAdapter
{
    /**
     * Lock parent then child/version in the same connection/current-read scope.
     * Independently enforce factual + private HR source/document authority.
     * Expectations: injury_id, expected_injury_revision, source_type, source_id,
     * expected_source_revision, expected_source_version_id. Reuse H&S canonical
     * current-source preconditions; narrow coordinator:{id,name} to its ID only.
     * Return ONLY injury_id, injury_revision, worker_id, source_site_id,
     * source:{type,id,revision,version_id}, attribution_state, recorded_by,
     * recorded_at, coordinator_user_id (nullable). No clinical/evidence content.
     */
    public function current(User $actor, array $expectations, CurrentAuthorizationReads $reads): array;

    /**
     * Verify immutable original version + exact parent/worker/Site and CURRENT
     * read authority. Aggregate bookkeeping may advance; this is not approval.
     * Must refuse missing/reparented versions or source/private access loss.
     */
    public function assertRecorded(User $actor, array $source, CurrentAuthorizationReads $reads): void;
}
