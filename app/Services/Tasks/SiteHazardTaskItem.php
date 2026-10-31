<?php

namespace App\Services\Tasks;

use Illuminate\Support\Carbon;

/** Hazard controls can be mitigated while their closure still needs attention. */
final class SiteHazardTaskItem extends TaskItem
{
    public function isOverdue(): bool
    {
        // dueAt preserves the source calendar date; its end-of-day instant is display context.
        return in_array($this->status, ['open', 'in_progress'], true)
            && $this->dueAt !== null
            && substr($this->dueAt, 0, 10) < Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
    }
}
