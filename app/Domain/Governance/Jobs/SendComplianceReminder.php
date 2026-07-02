<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\ComplianceReminder;
use App\Domain\Governance\Notifications\ComplianceReminderNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendComplianceReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ComplianceReminder $reminder
    ) {}

    public function handle(): void
    {
        $obligation = $this->reminder->obligation;
        if (! $obligation) {
            return;
        }

        // Recipients were already filtered to non-leavers by the engine before
        // dispatch; re-resolve defensively in case the queue ran later.
        $recipientIds = array_values(array_filter(array_map(
            'intval',
            (array) ($this->reminder->notified_users ?? []),
        )));
        if ($recipientIds === []) {
            return;
        }

        User::query()
            ->whereIn('id', $recipientIds)
            ->whereNotNull('approved_at')
            ->get()
            ->each(fn (User $user) => $user->notify(
                new ComplianceReminderNotification($this->reminder, $obligation)
            ));
    }
}
