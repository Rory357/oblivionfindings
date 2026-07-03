<?php

namespace App\Services\Tasks\Contracts;

use App\Models\User;

/**
 * A module feed whose items can be (re)assigned straight from the /tasks
 * queue. Implementations MUST mirror the owning module's assignment rules:
 * same permission gate as the module's own assign action, and the same
 * side-effect columns (assigned_at / assigned_by_user_id where they exist).
 * Modules with richer assignment ceremonies (triage flows, rosters) simply
 * don't implement this — their rows stay read-only in the queue.
 */
interface AssignableTaskProvider
{
    /** Mirror of the module's assign/manage permission. */
    public function canAssign(User $user): bool;

    /**
     * Assign (or unassign with null) the record. Throws
     * \Illuminate\Validation\ValidationException on module-rule violations.
     */
    public function assign(User $actor, int $id, ?int $assigneeId): void;
}
