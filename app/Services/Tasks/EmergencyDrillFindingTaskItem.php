<?php

namespace App\Services\Tasks;

use Illuminate\Support\Carbon;

/** Finding deadlines are source NZ calendar days, not transported instants. */
final class EmergencyDrillFindingTaskItem extends TaskItem
{
    public function isOverdue(): bool
    {
        return $this->bucket !== self::BUCKET_DONE
            && ! in_array($this->status, ['resolved', 'closed', 'accepted'], true)
            && $this->dueAt !== null
            && substr($this->dueAt, 0, 10) < Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
    }
}
