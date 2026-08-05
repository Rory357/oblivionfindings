<?php

namespace App\Services\Tasks\Contracts;

use App\Models\User;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;

/**
 * A module's feed into the company-wide /tasks dashboard.
 *
 * Providers return OPEN (non-terminal) work items by default; when
 * `$filters['include_done']` is true they may also include recently
 * closed items. Cross-cutting filtering (severity, assignee, search,
 * source toggles) happens centrally in {@see TaskAggregator} —
 * a provider only applies module-specific visibility rules (e.g. HR case
 * confidentiality) that the aggregator cannot know about.
 */
interface TaskProvider
{
    /** Stable source key used for filtering and grouping, e.g. "incident". */
    public function sourceKey(): string;

    /** Human label for the module filter, e.g. "Client Incidents". */
    public function label(): string;

    /** Mirror of the module's own route permission gate. */
    public function canView(User $user): bool;

    /**
     * @param  array{
     *   id?: int,
     *   include_done?: bool,
     *   q?: string|null,
     *   sources?: string[]|null,
     *   return_to?: string
     * }  $filters
     * @return TaskItem[]
     */
    public function tasks(User $user, array $filters = []): array;
}
