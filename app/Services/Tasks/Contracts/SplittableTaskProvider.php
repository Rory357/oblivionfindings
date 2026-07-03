<?php

namespace App\Services\Tasks\Contracts;

use App\Models\User;

/**
 * A module feed whose queue rows can be "split" into a child work item
 * (a follow-up / action plan) straight from the /tasks queue.
 *
 * Implementations MUST mirror the owning module's own child-create path:
 *  - the SAME permission gate as the module's child-create controller
 *    (e.g. incidents.followups.manage, or the concern `update` policy),
 *  - the SAME validation + column mapping (due column, body column,
 *    required vs. nullable assignee, priority/status defaults),
 *  - the SAME confidentiality/redaction rules the module enforces, and
 *  - the child record must link BACK to the parent so the new item is
 *    discoverable in the owning module (and, ideally, in the /tasks feed).
 *
 * The generic split action does NOT know any of these rules — it only
 * validates a thin cross-cutting shape (title/description/assignee/due) and
 * delegates the real work here. Rule, permission and redaction violations
 * are surfaced by throwing \Illuminate\Validation\ValidationException, which
 * the controller flattens into the global flash toast.
 */
interface SplittableTaskProvider
{
    /**
     * Human label for the child record type, used in UI copy such as
     * "You've been assigned a follow-up". Lower-case noun phrase, e.g.
     * "follow-up" or "action plan".
     */
    public function childLabel(): string;

    /**
     * Create a child work item under the parent record identified by $id.
     *
     * @param  array{title?: string, description?: ?string, assignee_id?: ?int, due_at?: ?string}  $data
     *   Cross-cutting shape from the queue's split form. Implementations map
     *   these onto their own columns and apply their own required/default
     *   rules on top.
     *
     * @return string|null  Optional deep link to the created child (or its
     *   parent), used as the assignment notification target. Null falls back
     *   to the parent item's link.
     *
     * @throws \Illuminate\Validation\ValidationException  on rule, permission
     *   or redaction violations.
     */
    public function createChild(User $actor, int $id, array $data): ?string;
}
