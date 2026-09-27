<?php

namespace App\Services\Fleet;

use App\Models\User;
use App\Notifications\Fleet\FleetSourceUpdateNotification;
use Illuminate\Support\Facades\DB;

class FleetOptionalNotice
{
    public static function afterCommit(int $recipientId, string $eventKey, int $sourceId): void
    {
        DB::afterCommit(function () use ($recipientId, $eventKey, $sourceId) {
            try {
                User::find($recipientId)?->notify(new FleetSourceUpdateNotification($eventKey, $sourceId));
            } catch (\Throwable $exception) {
                // An optional copy cannot roll back a committed source transition.
                report($exception);
            }
        });
    }
}
