<?php

namespace App\Console\Commands;

use App\Models\ConsentRequest;
use App\Services\ConsentRequestService;
use Illuminate\Console\Command;

/**
 * Sends a one-off reminder to recipients of pending ConsentRequest rows
 * whose expires_at falls between one and three days away. Idempotent —
 * skips rows whose audit_trail already contains a reminder_sent event.
 */
class SendConsentRequestReminders extends Command
{
    protected $signature = 'consent-requests:send-reminders';

    protected $description = 'Send reminders for pending consent requests expiring in one to three days.';

    public function handle(ConsentRequestService $service): int
    {
        $sent = 0;

        $windowStart = now()->addHours(24);
        $windowEnd = now()->addHours(72);

        ConsentRequest::query()
            ->pending()
            ->whereBetween('expires_at', [$windowStart, $windowEnd])
            ->lazy()
            ->each(function (ConsentRequest $request) use ($service, &$sent) {
                $trail = is_array($request->audit_trail) ? $request->audit_trail : [];

                foreach ($trail as $entry) {
                    if (($entry['event'] ?? null) === 'reminder_sent') {
                        return;
                    }
                }

                if ($service->sendReminder($request)) {
                    $sent++;
                }
            });

        $this->info(sprintf('Sent %d reminders', $sent));

        return self::SUCCESS;
    }
}
