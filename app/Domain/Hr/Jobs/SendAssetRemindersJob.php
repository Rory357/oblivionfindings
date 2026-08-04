<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\AssetService;
use App\Domain\Hr\Services\HrNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily HR asset reminder sweep — notifies asset managers about warranties hitting
 * a threshold, overdue returns, overdue repairs and assets still held by leavers.
 * Scheduled in routes/console.php and processed once for the single application.
 */
class SendAssetRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AssetService $assets, HrNotificationService $notifications): void
    {
        $alerts = $assets->dueAlerts();
        $sent = $notifications->sendAssetAlerts($alerts);

        Log::info('SendAssetRemindersJob: asset reminder sweep completed.', [
            'alerts' => count($alerts),
            'sent' => $sent,
        ]);
    }
}
