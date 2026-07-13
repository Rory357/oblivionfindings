<?php

namespace App\Jobs\Notifications;

use App\Models\ControlRoom\Communication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecoverControlRoomAlertNotificationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'control-room-alert-notification-recovery';
    }

    public function handle(): void
    {
        Communication::query()
            ->whereNotNull('delivery_key')
            ->where('purpose', 'notification')
            ->where('channel', 'in_app')
            ->whereIn('status', ['pending', 'failed'])
            ->whereNull('superseded_at')
            ->where('retry_count', '<', 3)
            ->where('updated_at', '<=', now()->subSeconds(30))
            ->orderBy('id')
            ->chunkById(100, function ($communications): void {
                foreach ($communications as $communication) {
                    $retryDelaySeconds = min(
                        300,
                        30 * (2 ** max(0, (int) $communication->retry_count)),
                    );
                    if ($communication->updated_at?->gt(now()->subSeconds($retryDelaySeconds))) {
                        continue;
                    }

                    DeliverControlRoomAlertNotificationJob::dispatch(
                        (int) $communication->id,
                    );
                }
            });
    }
}
