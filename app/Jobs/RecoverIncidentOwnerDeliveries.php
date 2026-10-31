<?php

namespace App\Jobs;

use App\Models\IncidentOwnerDelivery;
use App\Services\Incidents\IncidentOwnerNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RecoverIncidentOwnerDeliveries implements ShouldQueue
{
    use Queueable;

    public function handle(IncidentOwnerNotifications $notifications): void
    {
        // Technical delivery recovery, not a clinical/incident response deadline.
        $ids = IncidentOwnerDelivery::query()->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', 5)->where('updated_at', '<=', now()->subMinute())
            ->orderBy('updated_at')->limit(30)->pluck('id');
        foreach ($ids as $id) {
            try {
                $notifications->deliver($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
