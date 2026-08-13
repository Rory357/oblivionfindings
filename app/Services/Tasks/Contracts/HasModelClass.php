<?php

namespace App\Services\Tasks\Contracts;

/**
 * Lets the /tasks drawer resolve a provider's underlying Eloquent model so
 * the generic detail endpoint can attach the record's AuditLog timeline.
 * Purely informational — record access still flows through the provider's
 * own permission-and-row-scoped authorizedTasks() feed.
 */
interface HasModelClass
{
    /** Fully-qualified Eloquent class of the records this provider feeds. */
    public function modelClass(): string;
}
