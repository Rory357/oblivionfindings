<?php

namespace App\Policies;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItWorkTask;
use App\Models\User;

class ItWorkTaskPolicy
{
    public function __construct(private readonly ItWorkAccessService $access) {}

    public function view(User $user, ItWorkTask $task): bool
    {
        return $task->ticket !== null
            && $this->access->canView($user, $task->ticket);
    }

    public function update(User $user, ItWorkTask $task): bool
    {
        return $task->ticket !== null
            && $this->access->canWork($user, $task->ticket);
    }
}
