<?php

namespace App\Domain\Obli;

use App\Jobs\DispatchItTicketNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ObliTicketNotificationDispatch
{
    public static function schedule(int $id): void
    {
        try {
            DB::afterCommit(static function () use ($id): void {
                try {
                    DispatchItTicketNotifications::dispatchAfterResponse($id);
                } catch (Throwable) {
                    Log::warning('Obli ticket notification dispatch deferred.');
                }
            });
        } catch (Throwable) {
            Log::warning('Obli ticket notification scheduling deferred.');
        }
    }
}
