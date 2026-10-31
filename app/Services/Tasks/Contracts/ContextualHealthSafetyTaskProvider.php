<?php

namespace App\Services\Tasks\Contracts;

use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\TaskItem;

/** Source tasks must materialize while the consuming read context is active. */
interface ContextualHealthSafetyTaskProvider extends TaskProvider
{
    /** @return TaskItem[] */
    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array;

    /** Navigation identity/assignment/due state only; never a feed projection.
     * @return TaskItem[]
     */
    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array;
}
